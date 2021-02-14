<?php

namespace Nesk\Rialto;

use Nesk\Rialto\Exceptions\IdleTimeoutException;
use Nesk\Rialto\Exceptions\Node\Exception as NodeException;
use Nesk\Rialto\Exceptions\Node\FatalException as NodeFatalException;
use Nesk\Rialto\Exceptions\ReadSocketTimeoutException;
use Nesk\Rialto\Interfaces\ShouldHandleProcessDelegation;
use Nesk\Rialto\Transport\CurlTransport;
use Nesk\Rialto\Transport\Transport;
use Psr\Log\LogLevel;
use RuntimeException;
use Safe\Exceptions\CurlException;
use Safe\Exceptions\FilesystemException;
use Safe\Exceptions\JsonException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Safe\array_flip;
use function Safe\realpath;

class ProcessSupervisor
{
    use Data\UnserializesData;
    use Traits\UsesBasicResourceAsDefault;

    /**
     * A reasonable delay to let the process terminate itself (in milliseconds).
     *
     * @var int
     */
    protected const PROCESS_TERMINATION_DELAY = 100;

    /**
     * The size of a packet sent through the sockets (in bytes).
     *
     * @var int
     */
    protected const SOCKET_PACKET_SIZE = 1024;

    /**
     * The size of the header in each packet sent through the sockets (in bytes).
     *
     * @var int
     */
    protected const SOCKET_HEADER_SIZE = 5;

    /**
     * A short period to wait before reading the next chunk (in milliseconds), this avoids the next chunk to be read as
     * an empty string when PuPHPeteer is running on a slow environment.
     *
     * @var int
     */
    protected const SOCKET_NEXT_CHUNK_DELAY = 1;

    /**
     * Options to remove before sending them for the process.
     *
     * @var string[]
     */
    protected const USELESS_OPTIONS_FOR_PROCESS = [
        'executable_path', 'read_timeout', 'stop_timeout', 'logger', 'debug',
    ];

    /**
     * The associative array containing the options.
     *
     * @var array
     */
    protected $options = [
        // Node's executable path
        'executable_path' => 'node',

        // How much time (in seconds) the process can stay inactive before being killed (set to null to disable)
        'idle_timeout' => 60,

        // How much time (in seconds) an instruction can take to return a value (set to null to disable)
        'read_timeout' => 30,

        // How much time (in seconds) the process can take to shutdown properly before being killed
        'stop_timeout' => 3,

        // A logger instance for debugging (must implement \Psr\Log\LoggerInterface)
        'logger' => null,

        // Logs the output of console methods (console.log, console.debug, console.table, etc...) to the PHP logger
        'log_node_console' => false,

        // Enables debugging mode:
        //   - adds the --inspect flag to Node's command
        //   - appends stack traces to Node exception messages
        'debug' => false,
    ];

    /**
     * The running process.
     *
     * @var \Symfony\Component\Process\Process
     */
    protected $process;

    /**
     * The PID of the running process.
     *
     * @var int
     */
    protected $processPid;

    /**
     * The process delegate.
     *
     * @var \Nesk\Rialto\ShouldHandleProcessDelegation;
     */
    protected $delegate;

    /**
     * The client to communicate with the process.
     *
     * @var Transport
     */
    protected $client;

    /**
     * The server port.
     *
     * @var int
     */
    protected $serverPort;

    /**
     * The logger instance.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Constructor.
     */
    public function __construct(
        string $connectionDelegatePath,
        ?ShouldHandleProcessDelegation $processDelegate = null,
        array $options = []
    ) {
        $this->logger = new Logger($options['logger'] ?? null);

        $this->applyOptions($options);

        $this->process = $this->createNewProcess($connectionDelegatePath);

        $this->processPid = $this->startProcess($this->process);

        $this->delegate = $processDelegate;

        $this->client = new CurlTransport();
        $this->client->connect("http://localhost:{$this->serverPort()}", $this->options['read_timeout']);

        if ($this->options['debug']) {
            // Clear error output made by the "--inspect" flag
            $this->process->clearErrorOutput();
        }
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $logContext = ['pid' => $this->processPid];

        $this->waitForProcessTermination();

        if ($this->process->isRunning()) {
            $this->executeInstruction(Instruction::noop(), false); // Fetch the missing remote logs

            $this->logger->info('Stopping process with PID {pid}...', $logContext);
            $this->process->stop($this->options['stop_timeout']);
            $this->logger->info('Stopped process with PID {pid}', $logContext);
        } else {
            $this->logger->warning("The process cannot be stopped because it's no longer running", $logContext);
        }
    }

    /**
     * Log data from the process standard streams.
     */
    protected function logProcessStandardStreams(): void
    {
        $output = $this->process->getIncrementalOutput();
        if (\strlen($output) > 0) {
            $this->logger->notice('Received data on stdout: {output}', [
                'pid' => $this->processPid,
                'stream' => 'stdout',
                'output' => $output,
            ]);
        }

        $errorOutput = $this->process->getIncrementalErrorOutput();
        if (\strlen($errorOutput) > 0) {
            $this->logger->error('Received data on stderr: {output}', [
                'pid' => $this->processPid,
                'stream' => 'stderr',
                'output' => $errorOutput,
            ]);
        }
    }

    /**
     * Apply the options.
     */
    protected function applyOptions(array $options): void
    {
        $this->logger->info('Applying options...', ['options' => $options]);

        $this->options = \array_merge($this->options, $options);

        $this->logger->debug('Options applied and merged with defaults', ['options' => $this->options]);
    }

    /**
     * Return the script path of the Node process.
     *
     * In production, the script path must target the NPM package. In local development, the script path targets the
     * Composer package (since the NPM package is not installed).
     *
     * This avoids double declarations of some JS classes in production, due to a require with two different paths (one
     * with the NPM path, the other one with the Composer path).
     */
    protected function getProcessScriptPath(): string
    {
        static $scriptPath = null;

        if ($scriptPath !== null) {
            return $scriptPath;
        }

        // The script path in local development
        $scriptPath = __DIR__ . '/../node/serve.js';

        $process = new SymfonyProcess([
            $this->options['executable_path'],
            '-e',
            "process.stdout.write(require.resolve('@nesk/rialto/src/node/serve.js'))",
        ]);

        $exitCode = $process->run();

        if ($exitCode === 0) {
            // The script path in production
            $scriptPath = $process->getOutput();
        }

        return $scriptPath;
    }

    /**
     * Create a new Node process.
     *
     * @throws RuntimeException if the path to the connection delegate cannot be found.
     */
    protected function createNewProcess(string $connectionDelegatePath): SymfonyProcess
    {
        try {
            $realConnectionDelegatePath = realpath($connectionDelegatePath);
        } catch (FilesystemException $exception) {
            throw new RuntimeException("Cannot find file or directory '$connectionDelegatePath'.");
        }

        // Remove useless options for the process
        $processOptions = \array_diff_key($this->options, array_flip(self::USELESS_OPTIONS_FOR_PROCESS));

        return new SymfonyProcess(\array_merge(
            [$this->options['executable_path']],
            $this->options['debug'] ? ['--inspect'] : [],
            [$this->getProcessScriptPath()],
            [$realConnectionDelegatePath],
            [\json_encode((object) $processOptions, JSON_THROW_ON_ERROR)]
        ));
    }

    /**
     * Start the Node process.
     */
    protected function startProcess(SymfonyProcess $process): int
    {
        $this->logger->info('Starting process with command line: {commandline}', [
            'commandline' => $process->getCommandLine(),
        ]);

        $process->start();

        $pid = $process->getPid();

        $this->logger->info('Process started with PID {pid}', ['pid' => $pid]);

        return $pid;
    }

    /**
     * Check if the process is still running without errors.
     *
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    protected function checkProcessStatus(?\Throwable $previousException = null): void
    {
        $this->logProcessStandardStreams();

        $process = $this->process;

        if (\strlen($process->getErrorOutput()) > 0) {
            if (IdleTimeoutException::exceptionApplies($process)) {
                throw new IdleTimeoutException(
                    $this->options['idle_timeout'],
                    new NodeFatalException($process, $this->options['debug'], $previousException)
                );
            } elseif (NodeFatalException::exceptionApplies($process)) {
                throw new NodeFatalException($process, $this->options['debug'], $previousException);
            } elseif ($process->isTerminated() && !$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        }

        if ($process->isTerminated()) {
            throw new Exceptions\ProcessUnexpectedlyTerminatedException($process);
        }
    }

    /**
     * Wait for process termination.
     *
     * The process might take a while to stop itself. So, before trying to check its status or reading its standard
     * streams, this method should be executed.
     */
    protected function waitForProcessTermination(): void
    {
        \usleep(self::PROCESS_TERMINATION_DELAY * 1000);
    }

    /**
     * Return the port of the server.
     */
    protected function serverPort(): int
    {
        if ($this->serverPort !== null) {
            return $this->serverPort;
        }

        $iterator = $this->process->getIterator(SymfonyProcess::ITER_SKIP_ERR | SymfonyProcess::ITER_KEEP_OUTPUT);

        foreach ($iterator as $data) {
            return $this->serverPort = (int) $data;
        }

        // If the iterator didn't execute properly, then the process must have failed, we must check to be sure.
        $this->checkProcessStatus();
    }

    /**
     * Send an instruction to the process for execution.
     */
    public function executeInstruction(Instruction $instruction, bool $instructionShouldBeLogged = true)
    {
        // Check the process status because it could have crash in idle status.
        $this->checkProcessStatus();

        $serializedInstruction = \json_encode($instruction, JSON_THROW_ON_ERROR);

        if ($instructionShouldBeLogged) {
            $this->logger->debug('Sending an instruction to the port {port}...', [
                'pid' => $this->processPid,
                'port' => $this->serverPort(),

                // The instruction must be fully encoded and decoded to appear properly in the logs (this way,
                // JS functions and resources are serialized too).
                'instruction' => \json_decode($serializedInstruction, true, 512, JSON_THROW_ON_ERROR),
            ]);
        }

        try {
            $payload = $this->client->send($serializedInstruction);
        } catch (CurlException $exception) {
            // Check the process status before crashing on a CurlException, it's probably related.
            $this->checkProcessStatus($exception);

            if ($exception->getCode() === \CURLE_OPERATION_TIMEDOUT) {
                throw new ReadSocketTimeoutException($this->options['read_timeout'], $exception);
            }

            throw $exception;
        }

        return $this->processClientPayload($payload, $instructionShouldBeLogged);
    }

    /**
     * Read the next value written by the process.
     *
     * @return mixed
     */
    protected function processClientPayload(string $payload, bool $valueShouldBeLogged = true)
    {
        try {
            $data = \strlen($payload) > 0 ? \json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            $this->checkProcessStatus();
            throw $exception;
        }

        ['logs' => $logs, 'value' => $value] = $data;

        foreach ($logs ?? [] as $log) {
            $level = (new \ReflectionClass(LogLevel::class))->getConstant($log['level']);
            $messageContainsLineBreaks = \strstr($log['message'], PHP_EOL) !== false;
            $formattedMessage = $messageContainsLineBreaks ? "\n{log}\n" : '{log}';

            $this->logger->log($level, "Received a $log[origin] log: $formattedMessage", [
                'pid' => $this->processPid,
                'port' => $this->serverPort(),
                'log' => $log['message'],
            ]);
        }

        $value = $this->unserialize($value);

        if ($valueShouldBeLogged) {
            $this->logger->debug('Received data from the port {port}...', [
                'pid' => $this->processPid,
                'port' => $this->serverPort(),
                'data' => $value,
            ]);
        }

        if ($value instanceof NodeException) {
            throw $value;
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Nesk\Rialto\Transport;

use function Safe\curl_exec;
use function Safe\curl_init;
use function Safe\curl_setopt;

/**
 * @internal
 */
final class CurlTransport implements Transport
{
    /** @var \CurlHandle|null */
    private $curl;

    /**
     * Return curl instance as a resource. Safe curl_* functions doesn't support \CurlHandle for the moment so we trick
     * PHPStan with this method.
     *
     * @return resource
     */
    private function getCurlAsResource()
    {
        /** @var resource */
        $curl = $this->curl;
        return $curl;
    }

    public function connect(string $uri, float $sendTimeout): void
    {
        /** @var \CurlHandle */
        $curl = curl_init($uri);
        $this->curl = $curl;

        \curl_setopt_array($this->curl, [
            \CURLOPT_CUSTOMREQUEST => 'PATCH',
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_HEADER => false,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FAILONERROR => true,
            \CURLOPT_NOSIGNAL => true, // See: https://www.php.net/manual/en/function.curl-setopt.php#104597
            \CURLOPT_TIMEOUT_MS => $sendTimeout * 1000,
        ]);
    }

    public function send(string $data): string
    {
        if ($this->curl === null) {
            throw new \LogicException('CurlTransport::connect() must be called before CurlTransport::send().');
        }

        curl_setopt($this->getCurlAsResource(), \CURLOPT_POSTFIELDS, $data);

        $payload = curl_exec($this->getCurlAsResource());
        if (\is_bool($payload)) {
            throw new \LogicException('curl_exec should not return a boolean when CURLOPT_RETURNTRANSFER = true.');
        }

        return $payload;
    }
}

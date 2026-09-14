<?php
declare(strict_types=1);

namespace AthMovil\Http;

use AthMovil\Exception\TransportException;

final readonly class CurlTransport implements Transport
{
    public function __construct(private int $connectTimeoutSeconds = 10, private int $requestTimeoutSeconds = 30)
    {
        if ($connectTimeoutSeconds < 1 || $requestTimeoutSeconds < $connectTimeoutSeconds) {
            throw new \InvalidArgumentException('HTTP timeouts must be positive; request timeout must cover connection timeout.');
        }
    }

    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body): Response
    {
        // Prevent a simulated checkout from accidentally being sent to production.
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'authorization: bearer sim-')) {
                throw new \LogicException('Simulated credentials cannot be sent by CurlTransport.');
            }
        }
        $payload = json_decode($body, true);
        foreach (['publicToken', 'privateToken'] as $key) {
            if (is_array($payload) && is_string($payload[$key] ?? null) && str_starts_with($payload[$key], 'sim-')) {
                throw new \LogicException('Simulated credentials cannot be sent by CurlTransport.');
            }
        }
        if (!extension_loaded('curl')) {
            throw new \LogicException('Install ext-curl or supply a Transport implementation.');
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('HTTPS is required.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Unable to initialize HTTP transport.');
        }
        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            ]);
            if (!$configured) {
                throw new TransportException('Unable to configure HTTP transport.');
            }
            $responseBody = curl_exec($handle);
            if ($responseBody === false) {
                // Do not attach cURL output or request bodies to exceptions.
                throw new TransportException('HTTP transport failed; remote outcome is unknown.', curl_errno($handle));
            }
            return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseBody);
        } finally {
            curl_close($handle);
        }
    }
}

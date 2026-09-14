<?php
declare(strict_types=1);

namespace AthMovil\Http;

interface Transport
{
    /** Implementations must preserve TLS verification and must not redirect or retry requests. */
    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body): Response;
}

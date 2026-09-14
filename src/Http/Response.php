<?php
declare(strict_types=1);

namespace AthMovil\Http;

final readonly class Response
{
    public function __construct(public int $statusCode, public string $body) {}

    public function __debugInfo(): array
    {
        return ['statusCode' => $this->statusCode, 'body' => '[REDACTED]'];
    }
}

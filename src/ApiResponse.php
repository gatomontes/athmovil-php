<?php
declare(strict_types=1);

namespace AthMovil;

use AthMovil\Exception\ProtocolException;

readonly class ApiResponse
{
    public function __construct(private array $payload) {}

    /** Explicit access: may contain authorization tokens and personal information. */
    public function data(): mixed { return $this->payload['data'] ?? null; }

    public function ecommerceId(): ?string { return $this->field('ecommerceId'); }
    public function authToken(): ?string { return $this->field('auth_token'); }
    public function paymentStatus(): ?string { return $this->field('ecommerceStatus'); }
    public function referenceNumber(): ?string { return $this->field('referenceNumber'); }
    public function isCompleted(): bool { return $this->paymentStatus() === 'COMPLETED'; }

    public function requireString(string $key): string
    {
        $value = $this->field($key);
        if ($value === null || trim($value) === '') {
            throw new ProtocolException('ATH Movil response is missing a required string field.');
        }
        return $value;
    }

    private function field(string $key): ?string
    {
        $data = $this->data();
        return is_array($data) && is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    public function __debugInfo(): array { return ['payload' => '[REDACTED]']; }
}

<?php
declare(strict_types=1);

namespace AthMovil\Result;

use AthMovil\{Amount, ApiResponse};
use AthMovil\Exception\ProtocolException;

final readonly class PaymentResult extends ApiResponse
{
    public function metadata1(): ?string { return $this->metadata('metadata1'); }
    public function metadata2(): ?string { return $this->metadata('metadata2'); }
    public function items(): array
    {
        $data = $this->data();
        return is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];
    }
    public function total(): Amount
    {
        $data = $this->data();
        $value = is_array($data) ? ($data['total'] ?? null) : null;
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new ProtocolException('Payment response is missing its total.');
        }
        try { return Amount::fromDecimal((string) $value); }
        catch (\InvalidArgumentException) { throw new ProtocolException('Payment response contains an invalid total.'); }
    }
    public function isConfirmed(): bool { return in_array($this->paymentStatus(), ['CONFIRM', 'CONFIRMED'], true); }
    private function metadata(string $key): ?string
    {
        $data = $this->data();
        return is_array($data) && is_string($data[$key] ?? null) ? $data[$key] : null;
    }
}

<?php
declare(strict_types=1);

namespace AthMovil\Result;

use AthMovil\ApiResponse;

final readonly class RefundResult extends ApiResponse
{
    public function refund(): array { return $this->section('refund'); }
    public function originalTransaction(): array { return $this->section('originalTransaction'); }
    public function refundStatus(): ?string
    {
        $status = $this->refund()['status'] ?? null;
        return is_string($status) ? $status : null;
    }
    public function isRefundCompleted(): bool { return $this->refundStatus() === 'COMPLETED'; }
    private function section(string $key): array
    {
        $data = $this->data();
        return is_array($data) && is_array($data[$key] ?? null) ? $data[$key] : [];
    }
}

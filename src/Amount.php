<?php
declare(strict_types=1);

namespace AthMovil;

final readonly class Amount implements \Stringable
{
    private function __construct(public int $cents) {}

    public static function fromDecimal(string $value): self
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new \InvalidArgumentException('Use an unsigned decimal string with at most two decimal places.');
        }
        return new self(((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0'));
    }

    public function __toString(): string
    {
        return intdiv($this->cents, 100) . '.' . str_pad((string) ($this->cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

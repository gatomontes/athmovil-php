<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

final class FrozenClock implements Clock
{
    public function __construct(private int $timestamp = 1800000000) {}
    public function now(): int { return $this->timestamp; }
    public function advance(int $seconds): void
    {
        if ($seconds < 0) { throw new \InvalidArgumentException('Advance must be nonnegative.'); }
        $this->timestamp += $seconds;
    }
}

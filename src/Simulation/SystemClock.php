<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

final class SystemClock implements Clock
{
    public function now(): int { return time(); }
}

<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

enum Fault: string
{
    case Reject = 'reject';
    case TimeoutBefore = 'timeout_before';
    case TimeoutAfter = 'timeout_after';
}

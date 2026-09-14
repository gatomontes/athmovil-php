<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

enum Operation: string
{
    case Create = 'create';
    case Find = 'find';
    case Authorize = 'authorize';
    case UpdatePhone = 'update_phone';
    case Cancel = 'cancel';
    case Refund = 'refund';
}

<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

interface Store
{
    /**
     * Run atomically within one business namespace. The callback receives the state
     * array by reference. Commit on return; roll back if the callback throws.
     */
    public function transaction(string $scope, callable $callback): mixed;
}

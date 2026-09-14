<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

final class InMemoryStore implements Store
{
    private array $states = [];

    public function transaction(string $scope, callable $callback): mixed
    {
        $state = $this->states[$scope] ?? [];
        $result = $callback($state);
        $this->states[$scope] = $state;
        return $result;
    }

    public function __debugInfo(): array { return ['states' => '[REDACTED]']; }
}

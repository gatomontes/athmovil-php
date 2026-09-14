<?php
declare(strict_types=1);

namespace AthMovil\Exception;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly ?string $providerCode)
    {
        parent::__construct('ATH Movil rejected the request (HTTP ' . $httpStatus . ').');
    }
}

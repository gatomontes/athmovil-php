<?php
declare(strict_types=1);

namespace AthMovil\Result;

use AthMovil\ApiResponse;

final readonly class CreatedPayment extends ApiResponse
{
    public function ecommerceId(): string { return $this->requireString('ecommerceId'); }
    public function authToken(): string { return $this->requireString('auth_token'); }
}

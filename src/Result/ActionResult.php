<?php
declare(strict_types=1);

namespace AthMovil\Result;

use AthMovil\ApiResponse;

final readonly class ActionResult extends ApiResponse
{
    public function message(): ?string { return is_string($this->data()) ? $this->data() : null; }
}

<?php

namespace App\Services\CustomerApi\V2;

final class ApiProblem extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($errorCode);
    }
}

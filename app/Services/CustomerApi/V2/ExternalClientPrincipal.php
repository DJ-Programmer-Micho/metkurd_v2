<?php

namespace App\Services\CustomerApi\V2;

use App\Models\Customer;

interface ExternalClientPrincipal
{
    public function authorize(Customer $customer, string $scope, ?string $action = null): void;

    public function apiKeyId(): ?int;
}

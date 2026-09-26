<?php

namespace App\Services\CustomerApi\V2;

use App\Models\Customer;
use App\Models\CustomerApiKey;

final readonly class ApiKeyPrincipal implements ExternalClientPrincipal
{
    public function __construct(private CustomerApiKey $key) {}

    public function authorize(Customer $customer, string $scope, ?string $action = null): void
    {
        if ((int) $this->key->customer_id !== (int) $customer->id) {
            throw new ApiProblem('permission_denied', 403);
        }
        app(ApiCatalog::class)->authorize($customer, $this->key, $scope, $action);
    }

    public function apiKeyId(): ?int
    {
        return $this->key->id;
    }
}

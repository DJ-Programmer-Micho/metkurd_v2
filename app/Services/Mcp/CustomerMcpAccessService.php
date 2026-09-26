<?php

namespace App\Services\Mcp;

use App\Models\Customer;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiProblem;

/** Current effective non-Free access; grant origin is owned by billing authority. */
class CustomerMcpAccessService
{
    public function eligible(Customer $customer): bool
    {
        $customer = $customer->fresh();
        if (! $customer || (int) $customer->status !== 1) {
            return false;
        }
        $state = app(CustomerBillingStateService::class)->servicePlanState($customer);

        return $state['subscription'] !== null
            && (bool) $state['current_plan']->is_active
            && ! (bool) $state['current_plan']->is_free;
    }

    public function assertEligible(Customer $customer): void
    {
        if (! $this->eligible($customer)) {
            throw new ApiProblem('paid_plan_required', 403);
        }
        if (! app(ApiCatalog::class)->hasAccess($customer->fresh()) || $this->scopes($customer) === []) {
            throw new ApiProblem('api_access_unavailable', 403);
        }
    }

    public function scopes(Customer $customer): array
    {
        if (! $this->eligible($customer)) {
            return [];
        }
        $catalog = app(ApiCatalog::class);
        $allowed = $catalog->scopes($customer->fresh());
        $scopes = [];
        foreach ($catalog->variants() as $variant) {
            if (in_array($variant['scope'], $allowed, true) && $customer->isAllowed($variant['action'], 'api')) {
                $scopes[] = $variant['scope'];
            }
        }

        return $scopes ? array_values(array_unique([...$scopes, 'v2:jobs:read', 'v2:files:download', 'mcp:uploads'])) : [];
    }
}

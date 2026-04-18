<?php

namespace App\Support\Plans;

use App\Services\Plans\PlanConcurrencyService;

trait ResolvesConcurrentJobLimit
{
    protected function allowedConcurrentJobs(): int
    {
        /** @var \App\Models\Customer|null $customer */
        $customer = auth('app')->user();

        return app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer($customer);
    }
}

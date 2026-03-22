<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\Billing\CustomerOnboardingService;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        app(CustomerOnboardingService::class)->provisionDefaults($customer);
    }
}

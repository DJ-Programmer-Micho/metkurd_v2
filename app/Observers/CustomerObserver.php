<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\Billing\CustomerOnboardingService;
use Throwable;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        try {
            app(CustomerOnboardingService::class)->provisionDefaults($customer);
        } catch (Throwable $e) {
            report($e);

            Customer::withoutEvents(function () use ($customer) {
                if ($customer->exists) {
                    $customer->delete();
                }
            });

            throw $e;
        }
    }
}

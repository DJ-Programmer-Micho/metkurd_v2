<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CheckoutCreationGuard
{
    public function run(Customer $customer, string $model, callable $create): Payment
    {
        $kind = match ($model) {
            \App\Models\ServicePlan::class => 'service',
            \App\Models\StoragePlan::class => 'storage',
            \App\Models\CreditProduct::class => 'addon',
        };
        // All Create* callers, including V1 and V2, share this final provider-creation lock.
        $lock = Cache::lock('customer-purchase:'.$customer->id.':'.$kind, 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['checkout' => __('purchase_v2.busy')]);
        }
        try {
            if ($kind === 'service' && app(\App\Services\Billing\ServiceAgreementLifecycle::class)->hasReservedTerm($customer)) {
                throw ValidationException::withMessages(['checkout' => __('agreement.customer_help')]);
            }
            $policy = app(PaymentCheckoutState::class);
            foreach (Payment::currentBillingPeriod()->where('customer_id', $customer->id)->where('purchasable_type', $model)
                ->whereNull('fulfilled_at')->orderBy('id')->lazyById(100) as $old) {
                $policy->closeKnownCheckout($old);
            }
            if ($existing = $policy->blocker($customer, $model)) {
                return $existing;
            }

            return $create();
        } finally {
            $lock->release();
        }
    }
}

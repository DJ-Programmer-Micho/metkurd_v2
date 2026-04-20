<?php

namespace App\Services\Billing;

use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\CustomerStorageSubscription;
use App\Models\StoragePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleStoragePlanCancellation
{
    public function __construct(
        protected CustomerBillingStateService $billingState,
        protected FibSubscriptionService $fibSubscriptions,
    ) {
    }

    public function handle(Customer $customer): CustomerStorageSubscription
    {
        $state = $this->billingState->storageQuotaState($customer->fresh(['usage']));
        $subscription = $state['subscription'] ?? null;
        $plan = $state['current_plan'] ?? null;
        $periodEndsAt = $state['period_ends_at'] ?? null;
        $defaultPlan = $state['default_plan'] ?? null;

        if (! $subscription instanceof CustomerStorageSubscription || ! $plan instanceof StoragePlan || ! (bool) ($state['has_paid_storage_plan'] ?? false)) {
            throw ValidationException::withMessages([
                'plan' => __('Only active paid storage plans can be canceled.'),
            ]);
        }

        if (! $periodEndsAt) {
            throw ValidationException::withMessages([
                'plan' => __('We could not determine the current billing period end for this storage plan.'),
            ]);
        }

        return DB::transaction(function () use ($subscription, $periodEndsAt, $defaultPlan, $state) {
            /** @var CustomerStorageSubscription $locked */
            $locked = CustomerStorageSubscription::query()
                ->with(['storagePlan', 'payment'])
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($locked->canceled_at !== null && $locked->ends_at !== null && $locked->ends_at->isFuture()) {
                return $locked->fresh(['storagePlan', 'payment']);
            }

            $payment = $locked->payment;
            $shouldCancelProvider = $payment?->isProviderSubscriptionObject()
                && $payment->provider?->value === 'fib'
                && $locked->renewal_strategy === PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                && filled($payment->fib_subscription_id);

            if ($shouldCancelProvider) {
                $this->fibSubscriptions->cancel($payment);
            }

            $meta = (array) $locked->meta;
            $meta['scheduled_change'] = array_filter([
                'type' => 'downgrade_to_free_storage_plan',
                'effective_at' => $periodEndsAt?->toIso8601String(),
                'storage_plan_id' => $defaultPlan?->id,
                'storage_plan_code' => $defaultPlan?->code,
                'used_bytes' => $state['used_bytes'] ?? null,
                'current_limit_bytes' => $state['current_limit_bytes'] ?? null,
                'future_limit_bytes' => $state['future_limit_bytes'] ?? null,
            ], static fn (mixed $value) => $value !== null);
            if ($shouldCancelProvider) {
                $meta['provider_cancellation'] = [
                    'provider' => 'fib',
                    'provider_ref' => $payment?->providerReference(),
                    'requested_at' => now()->toIso8601String(),
                ];
            }

            $locked->forceFill([
                'auto_renew' => false,
                'canceled_at' => $locked->canceled_at ?? now(),
                'ends_at' => $periodEndsAt,
                'meta' => $meta,
            ])->save();

            return $locked->fresh(['storagePlan', 'payment']);
        }, 3);
    }
}

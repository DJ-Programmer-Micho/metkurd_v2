<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\CustomerStorageSubscription;
use App\Models\StoragePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleStoragePlanCancellation
{
    public function __construct(
        protected CustomerBillingStateService $billingState,
    ) {}

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

        return DB::transaction(function () use ($customer, $subscription, $periodEndsAt, $defaultPlan, $state) {
            Customer::whereKey($subscription->customer_id)->lockForUpdate()->firstOrFail();
            if ($subscription->payment_id) {
                \App\Domain\Payments\Models\Payment::whereKey($subscription->payment_id)->lockForUpdate()->firstOrFail();
            }
            /** @var CustomerStorageSubscription $locked */
            $locked = CustomerStorageSubscription::query()
                ->with(['storagePlan', 'payment'])
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if (! app(SubscriptionCyclePolicy::class)->isCurrent($locked)) {
                throw ValidationException::withMessages(['plan' => __('admin_p0.state_changed')]);
            }
            if ($locked->payment?->isProviderSubscriptionObject() && $locked->payment?->provider?->value === 'fib') {
                return app(ProviderSubscriptionCancellation::class)->customerCancel($customer, $locked);
            }

            $periodEndsAt = app(SubscriptionCyclePolicy::class)->boundary($locked);
            if (! $periodEndsAt) {
                throw ValidationException::withMessages(['plan' => __('We could not determine the current billing period end for this plan.')]);
            }
            if ($locked->canceled_at !== null && $locked->ends_at !== null && $locked->ends_at->isFuture()) {
                return $locked->fresh(['storagePlan', 'payment']);
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
            $meta['cancel_source'] = 'customer_web';

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

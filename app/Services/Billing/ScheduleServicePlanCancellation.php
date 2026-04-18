<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleServicePlanCancellation
{
    public function __construct(
        protected CustomerBillingStateService $billingState,
    ) {
    }

    public function handle(Customer $customer): CustomerServiceSubscription
    {
        $state = $this->billingState->servicePlanState($customer->fresh());
        $subscription = $state['subscription'] ?? null;
        $plan = $state['current_plan'] ?? null;
        $periodEndsAt = $state['period_ends_at'] ?? null;
        $defaultPlan = $state['default_plan'] ?? null;

        if (! $subscription instanceof CustomerServiceSubscription || ! $plan instanceof ServicePlan || (bool) ($plan->is_free ?? false)) {
            throw ValidationException::withMessages([
                'plan' => __('Only active paid plans can be canceled.'),
            ]);
        }

        if (! $periodEndsAt) {
            throw ValidationException::withMessages([
                'plan' => __('We could not determine the current billing period end for this plan.'),
            ]);
        }

        return DB::transaction(function () use ($subscription, $periodEndsAt, $defaultPlan) {
            /** @var CustomerServiceSubscription $locked */
            $locked = CustomerServiceSubscription::query()
                ->with('servicePlan')
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($locked->canceled_at !== null && $locked->ends_at !== null && $locked->ends_at->isFuture()) {
                return $locked->fresh(['servicePlan']);
            }

            $meta = (array) $locked->meta;
            $meta['scheduled_change'] = array_filter([
                'type' => 'downgrade_to_free_service_plan',
                'effective_at' => $periodEndsAt?->toIso8601String(),
                'service_plan_id' => $defaultPlan?->id,
                'service_plan_code' => $defaultPlan?->code,
            ], static fn (mixed $value) => $value !== null);

            $locked->forceFill([
                'auto_renew' => false,
                'canceled_at' => $locked->canceled_at ?? now(),
                'ends_at' => $periodEndsAt,
                'meta' => $meta,
            ])->save();

            return $locked->fresh(['servicePlan']);
        }, 3);
    }
}

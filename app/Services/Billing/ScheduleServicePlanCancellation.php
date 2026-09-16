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
    ) {}

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

        return DB::transaction(function () use ($customer, $subscription, $periodEndsAt, $defaultPlan) {
            Customer::whereKey($subscription->customer_id)->lockForUpdate()->firstOrFail();
            if ($subscription->payment_id) {
                \App\Domain\Payments\Models\Payment::whereKey($subscription->payment_id)->lockForUpdate()->firstOrFail();
            }
            /** @var CustomerServiceSubscription $locked */
            $locked = CustomerServiceSubscription::query()
                ->with(['servicePlan', 'payment'])
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if (! app(SubscriptionCyclePolicy::class)->isCurrent($locked)) {
                throw ValidationException::withMessages(['plan' => __('admin_p0.state_changed')]);
            }
            if ($locked->source === ServiceAgreementLifecycle::SOURCE) {
                throw ValidationException::withMessages(['plan' => __('agreement.customer_help')]);
            }
            if ($locked->payment?->isProviderSubscriptionObject() && $locked->payment?->provider?->value === 'fib') {
                return app(ProviderSubscriptionCancellation::class)->customerCancel($customer, $locked);
            }

            $periodEndsAt = app(SubscriptionCyclePolicy::class)->boundary($locked);
            if (! $periodEndsAt) {
                throw ValidationException::withMessages(['plan' => __('We could not determine the current billing period end for this plan.')]);
            }
            if ($locked->canceled_at !== null && $locked->ends_at !== null && $locked->ends_at->isFuture()) {
                return $locked->fresh(['servicePlan', 'payment']);
            }

            $meta = (array) $locked->meta;
            $meta['scheduled_change'] = array_filter([
                'type' => 'downgrade_to_free_service_plan',
                'effective_at' => $periodEndsAt?->toIso8601String(),
                'service_plan_id' => $defaultPlan?->id,
                'service_plan_code' => $defaultPlan?->code,
            ], static fn (mixed $value) => $value !== null);
            $meta['cancel_source'] = 'customer_web';

            $locked->forceFill([
                'auto_renew' => false,
                'canceled_at' => $locked->canceled_at ?? now(),
                'ends_at' => $periodEndsAt,
                'meta' => $meta,
            ])->save();

            return $locked->fresh(['servicePlan', 'payment']);
        }, 3);
    }
}

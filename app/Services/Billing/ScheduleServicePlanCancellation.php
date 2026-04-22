<?php

namespace App\Services\Billing;

use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleServicePlanCancellation
{
    public function __construct(
        protected CustomerBillingStateService $billingState,
        protected FibSubscriptionService $fibSubscriptions,
        protected FibSubscriptionCancellationService $fibSubscriptionCancellation,
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
                ->with(['servicePlan', 'payment'])
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($locked->canceled_at !== null && $locked->ends_at !== null && $locked->ends_at->isFuture()) {
                return $locked->fresh(['servicePlan', 'payment']);
            }

            $payment = $locked->payment;
            $shouldCancelProvider = $payment?->isProviderSubscriptionObject()
                && $payment->provider?->value === 'fib'
                && $locked->renewal_strategy === PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                && filled($payment->fib_subscription_id);

            if ($shouldCancelProvider) {
                $providerCancellation = $this->fibSubscriptionCancellation->cancel($payment);

                if ($providerCancellation['result'] === 'provider_error') {
                    throw ValidationException::withMessages([
                        'plan' => __('We could not confirm the provider cancellation right now. Please try again in a moment.'),
                    ]);
                }
            }

            $meta = (array) $locked->meta;
            $meta['scheduled_change'] = array_filter([
                'type' => 'downgrade_to_free_service_plan',
                'effective_at' => $periodEndsAt?->toIso8601String(),
                'service_plan_id' => $defaultPlan?->id,
                'service_plan_code' => $defaultPlan?->code,
            ], static fn (mixed $value) => $value !== null);
            if ($shouldCancelProvider) {
                $meta['provider_cancellation'] = [
                    'provider' => 'fib',
                    'provider_ref' => $payment?->providerReference(),
                    'requested_at' => now()->toIso8601String(),
                    'result' => $providerCancellation['result'] ?? 'cancel_requested',
                    'provider_status' => $providerCancellation['provider_status'] ?? null,
                ];
            }

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

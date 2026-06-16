<?php

namespace App\Services\Billing;

use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleServicePlanCancellation
{
    public function __construct(
        protected CustomerBillingStateService $billingState,
        protected FibSubscriptionService $fibSubscriptions,
        protected FibSubscriptionCancellationService $fibSubscriptionCancellation,
        protected PaymentEventRecorder $events,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
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
                    $event = $this->events->record($payment, [
                        'event_type' => 'service_subscription_cancel_request_failed',
                        'source' => 'website_cancel_request',
                        'event_key' => 'service-subscription-cancel-failed:'.$payment->id,
                        'before_status' => $payment->status->value,
                        'after_status' => $payment->status->value,
                        'meta' => [
                            'provider_status' => $providerCancellation['provider_status'] ?? null,
                            'trace_id' => $providerCancellation['trace_id'] ?? null,
                            'error_codes' => $providerCancellation['error_codes'] ?? [],
                            'period_ends_at' => $periodEndsAt?->toIso8601String(),
                        ],
                    ]);

                    if ($event->wasRecentlyCreated) {
                        $this->telegramLifecycleNotifier->send(
                            __('FIB subscription cancellation request failed'),
                            [
                                'Type' => 'service_subscription',
                                'Customer ID' => $locked->customer_id,
                                'Provider ref' => $payment?->providerReference(),
                                'Provider status' => $providerCancellation['provider_status'] ?? null,
                                'Trace ID' => $providerCancellation['trace_id'] ?? null,
                                'Error codes' => implode(', ', $providerCancellation['error_codes'] ?? []),
                            ],
                            'Service plan cancel'
                        );
                    }

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
                    'trace_id' => $providerCancellation['trace_id'] ?? null,
                    'error_codes' => $providerCancellation['error_codes'] ?? [],
                ];
            }
            $meta['cancel_source'] = 'customer_web';

            $locked->forceFill([
                'auto_renew' => false,
                'canceled_at' => $locked->canceled_at ?? now(),
                'ends_at' => $periodEndsAt,
                'meta' => $meta,
            ])->save();

            if ($shouldCancelProvider && $payment) {
                $result = (string) ($providerCancellation['result'] ?? 'cancel_requested');
                $event = $this->events->record($payment, [
                    'event_type' => 'service_subscription_cancel_requested',
                    'source' => 'website_cancel_request',
                    'event_key' => 'service-subscription-cancel-request:'.$payment->id.':'.$result.':'.sha1((string) $periodEndsAt?->toIso8601String()),
                    'before_status' => $payment->status->value,
                    'after_status' => $payment->status->value,
                    'meta' => [
                        'result' => $result,
                        'provider_status' => $providerCancellation['provider_status'] ?? null,
                        'period_ends_at' => $periodEndsAt?->toIso8601String(),
                    ],
                ]);

                if ($event->wasRecentlyCreated) {
                    $this->telegramLifecycleNotifier->send(
                        __('FIB subscription cancellation requested from website'),
                        [
                            'Type' => 'service_subscription',
                            'Customer ID' => $locked->customer_id,
                            'Plan' => $locked->servicePlan?->name,
                            'Provider ref' => $payment->providerReference(),
                            'Result' => $result,
                            'Service until' => $periodEndsAt?->toIso8601String(),
                        ],
                        'Service plan cancel'
                    );
                }
            }

            return $locked->fresh(['servicePlan', 'payment']);
        }, 3);
    }
}

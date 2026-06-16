<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\RecurringPaymentHandler;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Services\Billing\PlanSwitcher;
use App\Services\Coupons\CouponRedemptionService;
use App\Services\Payments\PaymentApplicationService;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FulfillPlanSubscription implements RecurringPaymentHandler
{
    public function __construct(
        protected PaymentEventRecorder $events,
        protected PlanSwitcher $switcher,
        protected CouponRedemptionService $redemptions,
        protected PaymentApplicationService $application,
        protected FibSubscriptionCancellationService $subscriptionCancellation,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
    ) {}

    public function supports(PurchaseType $purchaseType): bool
    {
        return $purchaseType === PurchaseType::PLAN_SUBSCRIPTION;
    }

    public function handle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->with(['customer.profile', 'purchasable'])->findOrFail($payment->id);

            if ($locked->fulfilled_at !== null || $locked->status !== PaymentStatus::PAID) {
                return;
            }

            $assessment = $this->application->assess($locked);

            if (! ($assessment['can_apply'] ?? false)) {
                $this->application->markRequiresReview(
                    $locked,
                    (string) ($assessment['reason'] ?? __('Payment received but the subscription requires manual review.')),
                    (array) ($assessment['context'] ?? []),
                );

                return;
            }

            $customer = $locked->customer;
            $plan = $locked->purchasable;
            $snapshot = $locked->snapshot();
            $feeQuote = $locked->feeQuote();
            $billingCycle = (string) ($snapshot['billing_cycle'] ?? 'monthly');
            $subscription = $this->switcher->switchServicePlan($customer, (int) $locked->purchasable_id, [
                'provider' => $locked->provider->value,
                'provider_ref' => $locked->providerReference(),
                'payment_method' => $locked->provider->value,
                'payment_id' => $locked->id,
                'coupon_id' => $locked->coupon_id,
                'coupon_code' => $locked->coupon_code,
                'merchant_transaction_id' => $locked->local_reference,
                'original_amount_iqd' => (int) round((float) ($locked->original_amount_iqd ?? data_get($snapshot, 'original_amount_iqd', 0))),
                'discount_amount_iqd' => (int) round((float) ($locked->discount_amount_iqd ?? data_get($snapshot, 'discount_amount_iqd', 0))),
                'base_amount_iqd' => (int) round((float) ($locked->discounted_amount_iqd ?? data_get($snapshot, 'amount_iqd', round((float) $locked->amount)))),
                'discounted_amount_iqd' => (int) round((float) ($locked->discounted_amount_iqd ?? data_get($snapshot, 'amount_iqd', round((float) $locked->amount)))),
                'gross_amount_iqd' => (int) ($feeQuote['gross_amount_iqd'] ?? round((float) $locked->amount)),
                'surcharge_amount_iqd' => (int) ($feeQuote['surcharge_amount_iqd'] ?? 0),
                'provider_fee_amount_iqd' => (int) ($feeQuote['provider_fee_amount_iqd'] ?? 0),
                'net_amount_iqd' => (int) ($feeQuote['net_amount_iqd'] ?? data_get($snapshot, 'amount_iqd', round((float) $locked->amount))),
                'fee_breakdown' => data_get($feeQuote, 'fee_breakdown'),
                'coupon' => data_get($snapshot, 'coupon'),
                'paid_at' => $locked->paid_at ?? now(),
                'billing_cycle' => $billingCycle,
                'renewal_strategy' => $locked->isProviderSubscriptionObject()
                    ? PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                    : PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                'active_until' => $locked->active_until,
                'provider_last_payment_at' => $locked->last_payment_at?->toIso8601String(),
                'provider_cycle_key' => $locked->providerRecurringCycleKey(),
            ]);

            $locked->forceFill([
                'fulfilled_at' => now(),
                'internal_status' => PaymentInternalStatus::APPLIED,
                'review_required_at' => null,
                'mismatch_reason' => null,
                'meta' => array_merge((array) $locked->meta, [
                    'fulfilled_subscription_id' => $subscription->id,
                ]),
            ])->save();

            $this->supersedeOlderFibPlanSubscriptions($locked, $subscription->id);

            $this->redemptions->consumeForPayment($locked);

            TelegramPaymentNotifier::send(
                $customer->fresh(['profile']),
                'Subscription Plan',
                (string) ($snapshot['name'] ?? $plan?->name ?? 'Plan'),
                [
                    'Billing Cycle' => ucfirst($billingCycle),
                    'Plan Code' => strtoupper((string) ($snapshot['code'] ?? $plan?->code ?? '')),
                    'Monthly Credits' => number_format((int) ($snapshot['monthly_credits'] ?? 0)),
                    'Price (IQD)' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'Estimated Local Price' => (string) data_get($snapshot, 'display.display_label', ''),
                    'Provider' => strtoupper($locked->provider->value),
                    'Reference' => $locked->providerReference(),
                ],
                'FIB plan fulfillment'
            );

            CustomerEmailNotifier::sendSubscriptionThankYou(
                $customer,
                [
                    'plan_name' => (string) ($snapshot['name'] ?? $plan?->name ?? 'Plan'),
                    'billing_cycle' => ucfirst($billingCycle),
                    'monthly_credits' => (int) ($snapshot['monthly_credits'] ?? 0),
                    'amount_label' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'activated_on' => now()->format('F d, Y'),
                ],
                'FIB plan fulfillment'
            );

            $this->events->record($locked, [
                'event_type' => 'payment_fulfilled',
                'source' => 'fulfillment_listener',
                'before_status' => $locked->status->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'subscription_id' => $subscription->id,
                ],
            ]);
        }, 3);
    }

    protected function supersedeOlderFibPlanSubscriptions(Payment $payment, int $subscriptionId): void
    {
        $olderPayments = Payment::query()
            ->where('customer_id', $payment->customer_id)
            ->where('id', '!=', $payment->id)
            ->where('provider', PaymentProvider::FIB)
            ->where('purchase_type', PurchaseType::PLAN_SUBSCRIPTION)
            ->where('payment_mode', PaymentMode::RECURRING)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->whereNotNull('fib_subscription_id')
            ->where(function ($query) {
                $query
                    ->whereNotNull('fulfilled_at')
                    ->orWhere('internal_status', PaymentInternalStatus::APPLIED);
            })
            ->where(function ($query) {
                $query
                    ->whereIn('provider_subscription_status', ['ACTIVE', 'SUBSCRIBED', 'PAID'])
                    ->orWhere(function ($activeUntil) {
                        $activeUntil
                            ->whereNotNull('active_until')
                            ->where('active_until', '>=', now());
                    });
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($olderPayments as $olderPayment) {
            $supersessionMeta = [
                'superseded_by_payment_id' => (int) $payment->id,
                'superseded_by_subscription_id' => $subscriptionId,
                'superseded_by_fib_subscription_id' => $payment->fib_subscription_id,
                'superseded_at' => now()->toIso8601String(),
            ];

            $providerCancellation = $this->subscriptionCancellation->cancel($olderPayment);
            $olderMeta = array_merge((array) ($olderPayment->meta ?? []), [
                'supersession' => array_merge($supersessionMeta, [
                    'provider_ref' => $olderPayment->providerReference(),
                    'provider_cancel_result' => $providerCancellation['result'] ?? null,
                    'provider_status' => $providerCancellation['provider_status'] ?? null,
                    'trace_id' => $providerCancellation['trace_id'] ?? null,
                    'error_codes' => $providerCancellation['error_codes'] ?? [],
                ]),
            ]);

            $olderPayment->forceFill([
                'meta' => $olderMeta,
            ])->save();

            $eventType = ($providerCancellation['result'] ?? null) === 'provider_error'
                ? 'provider_cancel_failed'
                : 'provider_cancel_requested';

            $this->events->record($olderPayment, [
                'event_type' => $eventType,
                'source' => 'superseded_plan_fulfillment',
                'event_key' => sprintf(
                    'superseded-plan:%d:%d:%s',
                    (int) $olderPayment->id,
                    (int) $payment->id,
                    (string) ($providerCancellation['result'] ?? 'unknown'),
                ),
                'before_status' => $olderPayment->status->value,
                'after_status' => $olderPayment->status->value,
                'meta' => array_merge($supersessionMeta, [
                    'provider_ref' => $olderPayment->providerReference(),
                    'provider_cancel_result' => $providerCancellation['result'] ?? null,
                    'provider_status' => $providerCancellation['provider_status'] ?? null,
                    'trace_id' => $providerCancellation['trace_id'] ?? null,
                    'error_codes' => $providerCancellation['error_codes'] ?? [],
                ]),
            ]);

            $this->events->record($olderPayment, [
                'event_type' => 'payment_superseded',
                'source' => 'superseded_plan_fulfillment',
                'event_key' => sprintf('payment-superseded:%d:%d', (int) $olderPayment->id, (int) $payment->id),
                'before_status' => $olderPayment->status->value,
                'after_status' => $olderPayment->status->value,
                'meta' => array_merge($supersessionMeta, [
                    'provider_cancel_result' => $providerCancellation['result'] ?? null,
                ]),
            ]);

            if (($providerCancellation['result'] ?? null) === 'provider_error') {
                Log::warning('Failed to cancel superseded FIB plan subscription.', [
                    'new_payment_id' => (int) $payment->id,
                    'old_payment_id' => (int) $olderPayment->id,
                    'customer_id' => (int) $payment->customer_id,
                    'new_provider_ref' => $payment->providerReference(),
                    'old_provider_ref' => $olderPayment->providerReference(),
                    'trace_id' => $providerCancellation['trace_id'] ?? null,
                    'error_codes' => $providerCancellation['error_codes'] ?? [],
                ]);

                $this->telegramLifecycleNotifier->send(
                    __('Failed to cancel superseded FIB service subscription'),
                    [
                        'Customer ID' => (int) $payment->customer_id,
                        'New provider ref' => $payment->providerReference(),
                        'Old provider ref' => $olderPayment->providerReference(),
                        'Old payment ID' => (int) $olderPayment->id,
                        'Trace ID' => $providerCancellation['trace_id'] ?? null,
                        'Error codes' => implode(', ', $providerCancellation['error_codes'] ?? []),
                    ],
                    'FIB plan supersession'
                );
            }
        }
    }
}

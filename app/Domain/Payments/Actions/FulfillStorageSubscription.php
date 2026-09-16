<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\RecurringPaymentHandler;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Services\Billing\PlanSwitcher;
use App\Services\Coupons\CouponRedemptionService;
use App\Services\Payments\PaymentApplicationService;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use Illuminate\Support\Facades\DB;

class FulfillStorageSubscription implements RecurringPaymentHandler
{
    public function __construct(
        protected PaymentEventRecorder $events,
        protected PlanSwitcher $switcher,
        protected CouponRedemptionService $redemptions,
        protected PaymentApplicationService $application,
    ) {}

    public function supports(PurchaseType $purchaseType): bool
    {
        return $purchaseType === PurchaseType::STORAGE_SUBSCRIPTION;
    }

    public function handle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            \App\Models\Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->with(['customer.profile', 'purchasable'])->findOrFail($payment->id);

            if (! $locked->isCurrentBillingPeriod() || $locked->fulfilled_at !== null || $locked->status !== PaymentStatus::PAID) {
                return;
            }

            $assessment = $this->application->assess($locked);

            if (! ($assessment['can_apply'] ?? false)) {
                $this->application->markRequiresReview(
                    $locked,
                    (string) ($assessment['reason'] ?? __('Payment received but the storage subscription requires manual review.')),
                    (array) ($assessment['context'] ?? []),
                );

                return;
            }

            $customer = $locked->customer;
            $plan = $locked->purchasable;
            $snapshot = $locked->snapshot();
            $feeQuote = $locked->feeQuote();
            $subscription = $this->switcher->switchStoragePlan($customer, (int) $locked->purchasable_id, [
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
                'renewal_strategy' => $locked->isProviderSubscriptionObject()
                    ? PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                    : PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                'active_until' => $locked->active_until,
            ]);

            $locked->forceFill([
                'fulfilled_at' => now(),
                'internal_status' => PaymentInternalStatus::APPLIED,
                'review_required_at' => null,
                'mismatch_reason' => null,
                'meta' => array_merge((array) $locked->meta, [
                    'fulfilled_storage_subscription_id' => $subscription->id,
                ]),
            ])->save();

            $this->redemptions->consumeForPayment($locked);

            TelegramPaymentNotifier::send(
                $customer->fresh(['profile']),
                'Storage Plan',
                (string) ($snapshot['name'] ?? $plan?->name ?? 'Storage Plan'),
                [
                    'Plan Code' => strtoupper((string) ($snapshot['code'] ?? $plan?->code ?? '')),
                    'Storage Quota (MB)' => number_format((int) ($snapshot['quota_mb'] ?? 0)),
                    'Amount (IQD)' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'Estimated Local Price' => (string) data_get($snapshot, 'display.display_label', ''),
                    'Provider' => strtoupper($locked->provider->value),
                    'Reference' => $locked->providerReference(),
                ],
                'FIB storage fulfillment'
            );

            CustomerEmailNotifier::sendStorageThankYou(
                $customer,
                [
                    'plan_name' => (string) ($snapshot['name'] ?? $plan?->name ?? 'Storage Plan'),
                    'quota_mb' => (int) ($snapshot['quota_mb'] ?? 0),
                    'amount_label' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'activated_on' => now()->format('F d, Y'),
                ],
                'FIB storage fulfillment'
            );

            $this->events->record($locked, [
                'event_type' => 'payment_fulfilled',
                'source' => 'fulfillment_listener',
                'before_status' => $locked->status->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'storage_subscription_id' => $subscription->id,
                ],
            ]);
        }, 3);
    }
}

<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Coupons\CouponContext;
use App\Services\Coupons\CouponRedemptionService;
use App\Services\Coupons\CouponService;
use App\Services\Payments\PaymentFeeCalculator;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePlanSubscriptionPayment
{
    public function __construct(
        protected \App\Domain\Payments\Fib\FibSubscriptionService $fib,
        protected BillingCurrencyService $currency,
        protected PaymentFeeCalculator $fees,
        protected PaymentEventRecorder $events,
        protected CouponService $coupons,
        protected CouponRedemptionService $redemptions,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
    ) {
    }

    public function handle(Customer $customer, int $planId, string $billingCycle = 'monthly', ?string $couponCode = null): Payment
    {
        $plan = ServicePlan::query()->where('is_active', true)->findOrFail($planId);
        $currentPlanId = $customer->currentServicePlanId();

        if ((int) $currentPlanId === (int) $plan->id) {
            throw ValidationException::withMessages([
                'plan' => __('This is already your current plan.'),
            ]);
        }

        $billingCycle = $this->fib->normalizeBillingCycle($billingCycle, ['monthly', 'yearly', 'hourly']);
        $originalBaseAmountIqd = $plan->priceIqdForCycle($billingCycle === 'yearly' ? 'yearly' : 'monthly');
        $couponContext = new CouponContext(
            customer: $customer,
            purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
            provider: 'fib',
            purchasableType: ServicePlan::class,
            purchasableId: (int) $plan->id,
            itemCode: (string) $plan->code,
            originalAmountIqd: $originalBaseAmountIqd,
            billingCycle: $billingCycle,
            isRecurring: true,
        );
        $resolvedCoupon = $this->coupons->resolveForCheckout($couponCode, $couponContext);
        $couponPricing = $resolvedCoupon['pricing'] ?? null;
        $baseAmountIqd = (int) ($couponPricing['final_amount_iqd'] ?? $originalBaseAmountIqd);
        $discountAmountIqd = (int) ($couponPricing['discount_amount_iqd'] ?? 0);
        $feeQuote = $this->fees->quote('fib', $baseAmountIqd);
        $grossAmountIqd = (int) ($feeQuote['gross_amount_iqd'] ?? $baseAmountIqd);
        $display = $this->currency->priceDataForBaseAmountIqd($grossAmountIqd, $customer);
        $baseDisplay = $this->currency->priceDataForBaseAmountIqd($baseAmountIqd, $customer);
        $originalDisplay = $this->currency->priceDataForBaseAmountIqd($originalBaseAmountIqd, $customer);
        $discountDisplay = $discountAmountIqd > 0
            ? $this->currency->priceDataForBaseAmountIqd($discountAmountIqd, $customer)
            : null;
        $payment = DB::transaction(function () use ($customer, $plan, $billingCycle, $couponContext, $resolvedCoupon, $couponPricing, $originalBaseAmountIqd, $baseAmountIqd, $discountAmountIqd, $grossAmountIqd, $feeQuote, $display, $baseDisplay, $originalDisplay, $discountDisplay) {
            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'coupon_id' => data_get($resolvedCoupon, 'coupon.id'),
                'coupon_code' => data_get($resolvedCoupon, 'coupon.code'),
                'provider' => PaymentProvider::FIB,
                'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
                'payment_mode' => PaymentMode::RECURRING,
                'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
                'status' => PaymentStatus::PENDING,
                'local_reference' => $this->localReference('PLAN'),
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $grossAmountIqd,
                'currency' => 'IQD',
                'original_amount_iqd' => $originalBaseAmountIqd,
                'discount_amount_iqd' => $discountAmountIqd,
                'discounted_amount_iqd' => $baseAmountIqd,
                'purchase_snapshot' => [
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'billing_cycle' => $billingCycle,
                    'monthly_credits' => (int) ($plan->monthly_credits ?? 0),
                    'original_amount_iqd' => $originalBaseAmountIqd,
                    'discount_amount_iqd' => $discountAmountIqd,
                    'amount_iqd' => $baseAmountIqd,
                    'gross_amount_iqd' => $grossAmountIqd,
                    'display' => $display,
                    'base_display' => $baseDisplay,
                    'original_display' => $originalDisplay,
                    'discount_display' => $discountDisplay,
                    'fee_quote' => $feeQuote,
                    'coupon' => $couponPricing ? array_merge($couponPricing, [
                        'original_display' => $originalDisplay,
                        'discount_display' => $discountDisplay,
                        'final_display' => $baseDisplay,
                    ]) : null,
                    'testing_cycle' => $billingCycle === 'hourly' ? [
                        'testing_only' => true,
                        'provider_interval' => $this->fib->intervalForCycle('hourly'),
                        'price_source_cycle' => 'monthly',
                    ] : null,
                    'renewal_strategy' => PaymentRecurringStrategy::PROVIDER_SCHEDULE->value,
                ],
                'meta' => [
                    'locale' => app()->getLocale(),
                    'fee_quote' => $feeQuote,
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'coupon' => $couponPricing,
                ],
                'purchasable_type' => ServicePlan::class,
                'purchasable_id' => $plan->id,
            ]);

            if (is_array($resolvedCoupon) && isset($resolvedCoupon['coupon'], $couponPricing)) {
                $this->redemptions->reserveCheckout(
                    $payment,
                    $resolvedCoupon['coupon'],
                    $couponContext,
                    $couponPricing,
                );
            }

            $this->events->record($payment, [
                'event_type' => 'local_payment_created',
                'source' => 'customer_checkout',
                'after_status' => $payment->status->value,
                'payload' => $payment->snapshot(),
            ]);

            return $payment;
        });

        return $this->initializeFibPayment($payment);
    }

    protected function initializeFibPayment(Payment $payment): Payment
    {
        try {
            $result = $this->fib->createSubscription($payment);

            /** @var \App\Domain\Payments\Data\FibCreateSubscriptionRequestData $request */
            $request = $result['request'];
            /** @var \App\Domain\Payments\Data\FibCreateSubscriptionResponseData $response */
            $response = $result['response'];

            $payment->forceFill([
                'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
                'provider_status' => (string) data_get($response->raw, 'status', 'UNPAID'),
                'provider_subscription_status' => (string) data_get($response->raw, 'status', 'UNPAID'),
                'fib_subscription_id' => $response->subscriptionId,
                'readable_code' => $response->readableCode,
                'qr_code' => $response->qrCode,
                'provider_links' => $response->providerLinks,
                'valid_until' => $response->validUntil,
                'provider_interval' => $request->interval,
                'provider_trial_period' => $request->trialPeriod,
                'create_payload' => $request->toArray(),
                'create_response' => $response->raw,
            ])->save();

            $event = $this->events->record($payment, [
                'event_type' => 'provider_subscription_created',
                'source' => 'customer_checkout',
                'event_key' => 'provider-subscription-created:' . $payment->id,
                'before_status' => PaymentStatus::PENDING->value,
                'after_status' => PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
                'payload' => $response->raw,
                'meta' => [
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'create_payload' => $request->toArray(),
                ],
            ]);

            if ($event->wasRecentlyCreated) {
                $snapshot = $payment->snapshot();
                $this->telegramLifecycleNotifier->send(
                    __('FIB recurring checkout created'),
                    [
                        'Type' => 'service_subscription',
                        'Customer ID' => $payment->customer_id,
                        'Username' => $payment->customer?->username,
                        'Plan' => (string) data_get($snapshot, 'name', ''),
                        'Plan code' => (string) data_get($snapshot, 'code', ''),
                        'Billing cycle' => (string) data_get($snapshot, 'billing_cycle', ''),
                        'Gross amount' => (string) data_get($snapshot, 'display.iqd_label', ''),
                        'Provider ref' => $payment->providerReference(),
                        'Payment UUID' => (string) $payment->uuid,
                    ],
                    'FIB plan checkout'
                );
            }

            $this->redemptions->markApplied($payment);

            return $payment->fresh();
        } catch (\Throwable $exception) {
            $payment->forceFill([
                'status' => PaymentStatus::FAILED,
                'status_reason' => $exception->getMessage(),
            ])->save();

            $this->redemptions->releaseForPayment($payment, 'provider_create_failed');

            $this->events->record($payment, [
                'event_type' => 'provider_subscription_create_failed',
                'source' => 'customer_checkout',
                'before_status' => PaymentStatus::PENDING->value,
                'after_status' => PaymentStatus::FAILED->value,
                'meta' => [
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'message' => $exception->getMessage(),
                ],
            ]);

            throw $exception;
        }
    }

    protected function localReference(string $prefix): string
    {
        return sprintf('FIB-%s-%s-%s', $prefix, now()->format('YmdHis'), strtoupper(Str::random(8)));
    }
}

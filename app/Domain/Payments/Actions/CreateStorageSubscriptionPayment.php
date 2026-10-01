<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentPurposeType;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Coupons\CouponContext;
use App\Services\Coupons\CouponRedemptionService;
use App\Services\Coupons\CouponService;
use App\Services\Payments\CheckoutAuthorizationService;
use App\Services\Payments\PaymentFeeCalculator;
use App\Services\Payments\PaymentMethodCatalog;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateStorageSubscriptionPayment
{
    public function __construct(
        protected \App\Domain\Payments\Fib\FibSubscriptionService $fib,
        protected \App\Domain\Payments\Fib\FibOneTimePaymentService $oneTimeFib,
        protected BillingCurrencyService $currency,
        protected PaymentFeeCalculator $fees,
        protected PaymentEventRecorder $events,
        protected CouponService $coupons,
        protected CouponRedemptionService $redemptions,
        protected CheckoutAuthorizationService $checkoutAuthorization,
        protected PaymentMethodCatalog $paymentMethods,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
    ) {}

    public function handle(
        Customer $customer,
        int $planId,
        string $billingCycle = 'monthly',
        ?string $couponCode = null,
        ?string $paymentMethodCode = null,
        bool $v2Checkout = false,
    ): Payment {
        return app(\App\Domain\Payments\Support\CheckoutCreationGuard::class)->run($customer, StoragePlan::class,
            fn () => $this->createCheckout($customer, $planId, $billingCycle, $couponCode, $paymentMethodCode, $v2Checkout));
    }

    protected function createCheckout(
        Customer $customer,
        int $planId,
        string $billingCycle = 'monthly',
        ?string $couponCode = null,
        ?string $paymentMethodCode = null,
        bool $v2Checkout = false,
    ): Payment {
        $plan = StoragePlan::query()->where('is_active', true)->findOrFail($planId);
        $paymentMode = $plan->checkoutPaymentMode();
        $currentPlan = $customer->currentStoragePlan();
        $currentPlanId = (int) ($currentPlan?->id ?? 0);

        if ($currentPlanId === (int) $plan->id) {
            throw ValidationException::withMessages([
                'plan' => __('This is already your current storage plan.'),
            ]);
        }

        $requestedMethodCode = strtolower(trim((string) ($paymentMethodCode ?? '')));
        $paymentMethod = $requestedMethodCode !== ''
            ? $this->checkoutAuthorization->assertPaymentMethodAvailable(
                $requestedMethodCode,
                PaymentPurposeType::STORAGE_PLAN,
                'IQD',
            )
            : ($paymentMode->isRecurring()
                ? $this->resolveDefaultRecurringMethod()
                : $this->resolveDefaultOneTimeMethod());

        if ($paymentMode->isRecurring() && ! (bool) ($paymentMethod->supports_recurring ?? false)) {
            throw ValidationException::withMessages([
                'payment_method' => __('The selected payment method does not support recurring storage subscriptions.'),
            ]);
        }

        $providerDriver = strtolower(trim((string) $paymentMethod->driver));
        $provider = PaymentProvider::tryFrom($providerDriver);

        if (! $provider instanceof PaymentProvider) {
            throw ValidationException::withMessages([
                'payment_method' => __('The selected payment method is not supported by this checkout flow yet.'),
            ]);
        }

        if ($provider !== PaymentProvider::FIB) {
            throw ValidationException::withMessages([
                'payment_method' => $paymentMode->isRecurring()
                    ? __('The selected payment method is not enabled yet for recurring storage subscriptions. Please use FIB for now.')
                    : __('The selected payment method is not enabled yet for manual storage payment. Please use FIB for now.'),
            ]);
        }

        $billingCycle = $this->resolveBillingCycle($billingCycle, $plan, $paymentMode);
        $originalBaseAmountIqd = $plan->priceIqdAmount();
        $couponContext = new CouponContext(
            customer: $customer,
            purchaseType: PurchaseType::STORAGE_SUBSCRIPTION,
            provider: $provider->value,
            purchasableType: StoragePlan::class,
            purchasableId: (int) $plan->id,
            itemCode: (string) $plan->code,
            originalAmountIqd: $originalBaseAmountIqd,
            billingCycle: $billingCycle,
            isRecurring: $paymentMode->isRecurring(),
        );
        $resolvedCoupon = $this->coupons->resolveForCheckout($couponCode, $couponContext);
        $couponPricing = $resolvedCoupon['pricing'] ?? null;
        $baseAmountIqd = (int) ($couponPricing['final_amount_iqd'] ?? $originalBaseAmountIqd);
        $discountAmountIqd = (int) ($couponPricing['discount_amount_iqd'] ?? 0);
        $feeQuote = $this->fees->quote($paymentMethod, $baseAmountIqd);
        $grossAmountIqd = (int) ($feeQuote['gross_amount_iqd'] ?? $baseAmountIqd);
        $display = $this->currency->priceDataForBaseAmountIqd($grossAmountIqd, $customer);
        $baseDisplay = $this->currency->priceDataForBaseAmountIqd($baseAmountIqd, $customer);
        $originalDisplay = $this->currency->priceDataForBaseAmountIqd($originalBaseAmountIqd, $customer);
        $discountDisplay = $discountAmountIqd > 0
            ? $this->currency->priceDataForBaseAmountIqd($discountAmountIqd, $customer)
            : null;
        $providerObjectType = $paymentMode->isRecurring()
            ? PaymentProviderObjectType::SUBSCRIPTION
            : PaymentProviderObjectType::PAYMENT;
        $payment = DB::transaction(function () use ($v2Checkout, $customer, $plan, $provider, $paymentMethod, $paymentMode, $providerObjectType, $billingCycle, $couponContext, $resolvedCoupon, $couponPricing, $originalBaseAmountIqd, $baseAmountIqd, $discountAmountIqd, $grossAmountIqd, $feeQuote, $display, $baseDisplay, $originalDisplay, $discountDisplay, $currentPlan, $currentPlanId) {
            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'coupon_id' => data_get($resolvedCoupon, 'coupon.id'),
                'coupon_code' => data_get($resolvedCoupon, 'coupon.code'),
                'provider' => $provider,
                'purchase_type' => PurchaseType::STORAGE_SUBSCRIPTION,
                'payment_mode' => $paymentMode,
                'provider_object_type' => $providerObjectType,
                'status' => PaymentStatus::PENDING,
                'internal_status' => PaymentInternalStatus::PENDING,
                'local_reference' => $this->localReference('STORAGE'),
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $grossAmountIqd,
                'currency' => 'IQD',
                'original_amount_iqd' => $originalBaseAmountIqd,
                'discount_amount_iqd' => $discountAmountIqd,
                'discounted_amount_iqd' => $baseAmountIqd,
                'purchase_snapshot' => [
                    'intended_plan' => [
                        'id' => (int) $plan->id,
                        'code' => (string) $plan->code,
                        'name' => (string) $plan->name,
                    ],
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'billing_cycle' => $billingCycle,
                    'quota_mb' => (int) ($plan->quota_mb ?? 0),
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
                    'renewal_strategy' => $paymentMode->isRecurring()
                        ? PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                        : PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                    'checkout_context' => [
                        'current_storage_plan_id' => $currentPlanId > 0 ? $currentPlanId : null,
                        'current_storage_plan_code' => $currentPlan?->code,
                        'current_storage_plan_name' => $currentPlan?->name,
                    ],
                ],
                'meta' => [
                    'locale' => app()->getLocale(),
                    'checkout_ui' => $v2Checkout ? 'v2' : 'v1',
                    'persistence_version' => $v2Checkout ? 2 : 1,
                    'fee_quote' => $feeQuote,
                    'payment_method_code' => (string) $paymentMethod->code,
                    'payment_driver' => (string) $paymentMethod->driver,
                    'provider_object_type' => $providerObjectType->value,
                    'coupon' => $couponPricing,
                ],
                'purchasable_type' => StoragePlan::class,
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

        return $paymentMode->isRecurring()
            ? $this->initializeRecurringFibPayment($payment)
            : $this->initializeOneTimeFibPayment($payment);
    }

    protected function initializeRecurringFibPayment(Payment $payment): Payment
    {
        try {
            $result = $this->fib->createSubscription($payment);

            /** @var \App\Domain\Payments\Data\FibCreateSubscriptionRequestData $request */
            $request = $result['request'];
            /** @var \App\Domain\Payments\Data\FibCreateSubscriptionResponseData $response */
            $response = $result['response'];

            \App\Domain\Payments\Support\CheckoutQrCache::remember($payment, $response->qrCode, $response->validUntil);

            $payment->forceFill([
                'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
                'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
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
                'event_key' => 'provider-subscription-created:'.$payment->id,
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
                $this->telegramLifecycleNotifier->sendCheckout(
                    __('FIB recurring checkout created'),
                    [
                        'Type' => 'storage_subscription',
                        'Customer ID' => $payment->customer_id,
                        'Username' => $payment->customer?->username,
                        'Storage plan' => (string) data_get($snapshot, 'name', ''),
                        'Storage code' => (string) data_get($snapshot, 'code', ''),
                        'Billing cycle' => (string) data_get($snapshot, 'billing_cycle', ''),
                        'Gross amount' => (string) data_get($snapshot, 'display.iqd_label', ''),
                        'Provider ref' => $payment->providerReference(),
                        'Payment UUID' => (string) $payment->uuid,
                    ],
                    'FIB storage checkout'
                );
            }

            $this->redemptions->markApplied($payment);

            return $payment->fresh();
        } catch (\Throwable $exception) {
            $payment->forceFill([
                'status' => PaymentStatus::FAILED,
                'internal_status' => PaymentInternalStatus::FAILED,
                'status_reason' => $exception->getMessage(),
                'failed_at' => now(),
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

            $this->logCheckoutInitializationFailure($payment, PaymentMode::RECURRING, $exception);

            throw $exception;
        }
    }

    protected function initializeOneTimeFibPayment(Payment $payment): Payment
    {
        try {
            $result = $this->oneTimeFib->createPayment(
                $payment,
                route(data_get($payment->meta, 'checkout_ui') === 'v2' ? 'app.v2.payments.fib.show' : 'payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $payment])
            );

            /** @var \App\Domain\Payments\Data\FibCreatePaymentRequestData $request */
            $request = $result['request'];
            /** @var \App\Domain\Payments\Data\FibCreatePaymentResponseData $response */
            $response = $result['response'];

            \App\Domain\Payments\Support\CheckoutQrCache::remember($payment, $response->qrCode, $response->validUntil);

            $payment->forceFill([
                'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
                'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
                'provider_status' => 'UNPAID',
                'provider_payment_status' => 'UNPAID',
                'fib_payment_id' => $response->paymentId,
                'readable_code' => $response->readableCode,
                'qr_code' => $response->qrCode,
                'provider_links' => $response->providerLinks,
                'valid_until' => $response->validUntil,
                'create_payload' => $request->toArray(),
                'create_response' => $response->raw,
            ])->save();

            $event = $this->events->record($payment, [
                'event_type' => 'provider_payment_created',
                'source' => 'customer_checkout',
                'event_key' => 'provider-payment-created:'.$payment->id,
                'before_status' => PaymentStatus::PENDING->value,
                'after_status' => PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
                'payload' => $response->raw,
                'meta' => [
                    'provider_object_type' => PaymentProviderObjectType::PAYMENT->value,
                    'create_payload' => $request->toArray(),
                ],
            ]);

            if ($event->wasRecentlyCreated) {
                $snapshot = $payment->snapshot();
                $this->telegramLifecycleNotifier->sendCheckout(
                    __('FIB checkout created'),
                    [
                        'Type' => 'storage_plan',
                        'Customer ID' => $payment->customer_id,
                        'Username' => $payment->customer?->username,
                        'Storage plan' => (string) data_get($snapshot, 'name', ''),
                        'Storage code' => (string) data_get($snapshot, 'code', ''),
                        'Billing cycle' => (string) data_get($snapshot, 'billing_cycle', ''),
                        'Gross amount' => (string) data_get($snapshot, 'display.iqd_label', ''),
                        'Provider ref' => $payment->providerReference(),
                        'Payment UUID' => (string) $payment->uuid,
                    ],
                    'FIB storage checkout'
                );
            }

            $this->redemptions->markApplied($payment);

            return $payment->fresh();
        } catch (\Throwable $exception) {
            $payment->forceFill([
                'status' => PaymentStatus::FAILED,
                'internal_status' => PaymentInternalStatus::FAILED,
                'status_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            $this->redemptions->releaseForPayment($payment, 'provider_create_failed');

            $this->events->record($payment, [
                'event_type' => 'provider_payment_create_failed',
                'source' => 'customer_checkout',
                'before_status' => PaymentStatus::PENDING->value,
                'after_status' => PaymentStatus::FAILED->value,
                'meta' => [
                    'provider_object_type' => PaymentProviderObjectType::PAYMENT->value,
                    'message' => $exception->getMessage(),
                ],
            ]);

            $this->logCheckoutInitializationFailure($payment, PaymentMode::ONE_TIME, $exception);

            throw $exception;
        }
    }

    protected function localReference(string $prefix): string
    {
        return sprintf('FIB-%s-%s-%s', $prefix, now()->format('YmdHis'), strtoupper(Str::random(8)));
    }

    protected function resolveDefaultRecurringMethod(): PaymentMethod
    {
        $methods = $this->paymentMethods
            ->availableForPurpose(PaymentPurposeType::STORAGE_PLAN, 'IQD')
            ->filter(fn (PaymentMethod $method) => (bool) ($method->supports_recurring ?? false))
            ->values();

        if ($methods->isEmpty()) {
            throw ValidationException::withMessages([
                'payment_method' => __('No recurring payment method is currently available for storage subscriptions.'),
            ]);
        }

        $preferred = strtolower(trim((string) config('payments.default_provider', '')));

        if ($preferred !== '') {
            $preferredMethod = $methods->first(fn (PaymentMethod $method) => $method->code === $preferred || $method->driver === $preferred);

            if ($preferredMethod instanceof PaymentMethod) {
                return $preferredMethod;
            }

            /** @var PaymentMethod|null $fallback */
            $fallback = $methods->first();
            Log::warning('Configured payment default provider is unavailable for storage recurring checkout; using fallback method.', [
                'preferred_provider' => $preferred,
                'purpose_type' => PaymentPurposeType::STORAGE_PLAN->value,
                'fallback_method_code' => $fallback?->code,
                'fallback_method_driver' => $fallback?->driver,
                'available_method_codes' => $methods->map(fn (PaymentMethod $method): string => (string) $method->code)->values()->all(),
            ]);
        }

        $fibMethod = $methods->first(fn (PaymentMethod $method) => $method->code === 'fib' || $method->driver === 'fib');

        if ($fibMethod instanceof PaymentMethod) {
            return $fibMethod;
        }

        /** @var PaymentMethod $fallback */
        $fallback = $methods->first();

        return $fallback;
    }

    protected function resolveDefaultOneTimeMethod(): PaymentMethod
    {
        $methods = $this->paymentMethods
            ->availableForPurpose(PaymentPurposeType::STORAGE_PLAN, 'IQD')
            ->values();

        if ($methods->isEmpty()) {
            throw ValidationException::withMessages([
                'payment_method' => __('No manual payment method is currently available for storage purchases.'),
            ]);
        }

        $preferred = strtolower(trim((string) config('payments.default_provider', '')));

        if ($preferred !== '') {
            $preferredMethod = $methods->first(fn (PaymentMethod $method) => $method->code === $preferred || $method->driver === $preferred);

            if ($preferredMethod instanceof PaymentMethod) {
                return $preferredMethod;
            }

            /** @var PaymentMethod|null $fallback */
            $fallback = $methods->first();
            Log::warning('Configured payment default provider is unavailable for storage manual-payment checkout; using fallback method.', [
                'preferred_provider' => $preferred,
                'purpose_type' => PaymentPurposeType::STORAGE_PLAN->value,
                'fallback_method_code' => $fallback?->code,
                'fallback_method_driver' => $fallback?->driver,
                'available_method_codes' => $methods->map(fn (PaymentMethod $method): string => (string) $method->code)->values()->all(),
            ]);
        }

        $fibMethod = $methods->first(fn (PaymentMethod $method) => $method->code === 'fib' || $method->driver === 'fib');

        if ($fibMethod instanceof PaymentMethod) {
            return $fibMethod;
        }

        /** @var PaymentMethod $fallback */
        $fallback = $methods->first();

        return $fallback;
    }

    protected function resolveBillingCycle(string $billingCycle, StoragePlan $plan, PaymentMode $paymentMode): string
    {
        $normalized = $paymentMode->isRecurring()
            ? $this->fib->normalizeBillingCycle($billingCycle, ['monthly', 'yearly', 'hourly'])
            : $this->normalizeOneTimeBillingCycle($billingCycle);

        $supportCycle = $normalized === 'hourly' ? 'monthly' : $normalized;

        if (! $plan->supportsBillingInterval($supportCycle)) {
            throw ValidationException::withMessages([
                'billing_cycle' => __('This billing cycle is not available for the selected storage plan.'),
            ]);
        }

        return $normalized;
    }

    protected function normalizeOneTimeBillingCycle(string $billingCycle): string
    {
        $normalized = strtolower(trim($billingCycle));

        return in_array($normalized, ['monthly', 'yearly'], true) ? $normalized : 'monthly';
    }

    protected function logCheckoutInitializationFailure(Payment $payment, PaymentMode $mode, \Throwable $exception): void
    {
        Log::error('Failed to initialize storage checkout with FIB.', [
            'payment_id' => (int) $payment->id,
            'payment_uuid' => (string) $payment->uuid,
            'customer_id' => (int) $payment->customer_id,
            'payment_mode' => $mode->value,
            'provider_object_type' => (string) ($payment->provider_object_type?->value ?? ''),
            'provider' => (string) ($payment->provider?->value ?? ''),
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}

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
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Coupons\CouponContext;
use App\Services\Coupons\CouponRedemptionService;
use App\Services\Coupons\CouponService;
use App\Services\Payments\CheckoutAuthorizationService;
use App\Services\Payments\PaymentFeeCalculator;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAddonPayment
{
    public function __construct(
        protected \App\Domain\Payments\Fib\FibOneTimePaymentService $fib,
        protected BillingCurrencyService $currency,
        protected CheckoutAuthorizationService $authorization,
        protected PaymentFeeCalculator $fees,
        protected PaymentEventRecorder $events,
        protected CouponService $coupons,
        protected CouponRedemptionService $redemptions,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
    ) {}

    public function handle(Customer $customer, int $productId, ?string $couponCode = null, bool $v2Checkout = false): Payment
    {
        return app(\App\Domain\Payments\Support\CheckoutCreationGuard::class)->run($customer, CreditProduct::class,
            fn () => $this->createCheckout($customer, $productId, $couponCode, $v2Checkout));
    }

    protected function createCheckout(Customer $customer, int $productId, ?string $couponCode = null, bool $v2Checkout = false): Payment
    {
        $this->authorization->assertAddonPurchaseAllowed($customer);

        $product = CreditProduct::query()->where('is_active', true)->findOrFail($productId);
        $originalBaseAmountIqd = $product->priceIqdAmount();
        $couponContext = new CouponContext(
            customer: $customer,
            purchaseType: PurchaseType::ADDON_CREDITS,
            provider: 'fib',
            purchasableType: CreditProduct::class,
            purchasableId: (int) $product->id,
            itemCode: (string) $product->code,
            originalAmountIqd: $originalBaseAmountIqd,
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
        $payment = DB::transaction(function () use ($v2Checkout, $customer, $product, $couponContext, $resolvedCoupon, $couponPricing, $originalBaseAmountIqd, $baseAmountIqd, $discountAmountIqd, $grossAmountIqd, $feeQuote, $display, $baseDisplay, $originalDisplay, $discountDisplay) {
            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'coupon_id' => data_get($resolvedCoupon, 'coupon.id'),
                'coupon_code' => data_get($resolvedCoupon, 'coupon.code'),
                'provider' => PaymentProvider::FIB,
                'purchase_type' => PurchaseType::ADDON_CREDITS,
                'payment_mode' => PaymentMode::ONE_TIME,
                'provider_object_type' => PaymentProviderObjectType::PAYMENT,
                'status' => PaymentStatus::PENDING,
                'internal_status' => PaymentInternalStatus::PENDING,
                'local_reference' => $this->localReference('ADDON'),
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $grossAmountIqd,
                'currency' => 'IQD',
                'original_amount_iqd' => $originalBaseAmountIqd,
                'discount_amount_iqd' => $discountAmountIqd,
                'discounted_amount_iqd' => $baseAmountIqd,
                'purchase_snapshot' => [
                    'code' => (string) $product->code,
                    'name' => (string) $product->name,
                    'credits_amount' => (int) ($product->credits_amount ?? 0),
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
                ],
                'meta' => [
                    'locale' => app()->getLocale(),
                    'checkout_ui' => $v2Checkout ? 'v2' : 'v1',
                    'fee_quote' => $feeQuote,
                    'coupon' => $couponPricing,
                ],
                'purchasable_type' => CreditProduct::class,
                'purchasable_id' => $product->id,
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

        try {
            $result = $this->fib->createPayment(
                $payment,
                route(data_get($payment->meta, 'checkout_ui') === 'v2' ? 'app.v2.payments.fib.show' : 'payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $payment])
            );

            /** @var \App\Domain\Payments\Data\FibCreatePaymentRequestData $request */
            $request = $result['request'];
            /** @var \App\Domain\Payments\Data\FibCreatePaymentResponseData $response */
            $response = $result['response'];

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
                        'Type' => 'addon_credits',
                        'Customer ID' => $payment->customer_id,
                        'Username' => $payment->customer?->username,
                        'Product' => (string) data_get($snapshot, 'name', ''),
                        'Product code' => (string) data_get($snapshot, 'code', ''),
                        'Credits' => number_format((int) data_get($snapshot, 'credits_amount', 0)),
                        'Gross amount' => (string) data_get($snapshot, 'display.iqd_label', ''),
                        'Provider ref' => $payment->providerReference(),
                        'Payment UUID' => (string) $payment->uuid,
                    ],
                    'FIB addon checkout'
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

            throw $exception;
        }
    }

    protected function localReference(string $prefix): string
    {
        return sprintf('FIB-%s-%s-%s', $prefix, now()->format('YmdHis'), strtoupper(Str::random(8)));
    }
}

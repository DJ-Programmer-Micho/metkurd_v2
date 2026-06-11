<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\OneTimePaymentHandler;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Services\Coupons\CouponRedemptionService;
use App\Services\Payments\AddonPurchaseService;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use Illuminate\Support\Facades\DB;

class FulfillAddonCredits implements OneTimePaymentHandler
{
    public function __construct(
        protected PaymentEventRecorder $events,
        protected AddonPurchaseService $addons,
        protected CouponRedemptionService $redemptions,
    ) {}

    public function supports(PurchaseType $purchaseType): bool
    {
        return $purchaseType === PurchaseType::ADDON_CREDITS;
    }

    public function handle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->with(['customer.profile', 'purchasable'])->findOrFail($payment->id);

            if ($locked->fulfilled_at !== null || $locked->status !== PaymentStatus::PAID) {
                return;
            }

            $customer = $locked->customer;
            $product = $locked->purchasable;
            $snapshot = $locked->snapshot();
            $feeQuote = $locked->feeQuote();
            $order = $this->addons->purchase($customer, (int) $locked->purchasable_id, [
                'provider' => $locked->provider->value,
                'provider_ref' => $locked->fib_payment_id ?: $locked->local_reference,
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
            ]);

            $locked->forceFill([
                'fulfilled_at' => now(),
                'internal_status' => PaymentInternalStatus::APPLIED,
                'review_required_at' => null,
                'mismatch_reason' => null,
                'meta' => array_merge((array) $locked->meta, [
                    'fulfilled_order_id' => $order->id,
                ]),
            ])->save();

            $this->redemptions->consumeForPayment($locked);

            TelegramPaymentNotifier::send(
                $customer->fresh(['profile']),
                'Add-on Credits',
                (string) ($snapshot['name'] ?? $product?->name ?? 'Add-on Credits'),
                [
                    'Plan Code' => strtoupper((string) ($snapshot['code'] ?? $product?->code ?? '')),
                    'Credits' => number_format((int) ($snapshot['credits_amount'] ?? 0)),
                    'Amount (IQD)' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'Estimated Local Price' => (string) data_get($snapshot, 'display.display_label', ''),
                    'Order Type' => (string) ($order->order_type ?? 'addon'),
                    'Provider' => strtoupper($locked->provider->value),
                    'Reference' => (string) ($locked->fib_payment_id ?: $locked->local_reference),
                ],
                'FIB addon fulfillment'
            );

            CustomerEmailNotifier::sendAddonThankYou(
                $customer,
                [
                    'product_name' => (string) ($snapshot['name'] ?? $product?->name ?? 'Add-on Credits'),
                    'credits_amount' => (int) ($snapshot['credits_amount'] ?? 0),
                    'amount_label' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'added_on' => $order->created_at?->format('F d, Y') ?? now()->format('F d, Y'),
                    'status_label' => 'Completed',
                ],
                'FIB addon fulfillment'
            );

            $this->events->record($locked, [
                'event_type' => 'payment_fulfilled',
                'source' => 'fulfillment_listener',
                'before_status' => $locked->status->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'order_id' => $order->id,
                ],
            ]);
        }, 3);
    }
}

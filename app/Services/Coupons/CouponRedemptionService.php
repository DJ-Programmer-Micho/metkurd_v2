<?php

namespace App\Services\Coupons;

use App\Domain\Payments\Models\Payment;
use App\Enums\CouponRedemptionStatus;
use App\Enums\CouponRedemptionType;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponRedemptionService
{
    public function __construct(
        protected CouponService $coupons,
    ) {}

    /**
     * @param  array<string, mixed>  $pricing
     */
    public function reserveCheckout(Payment $payment, Coupon $coupon, CouponContext $context, array $pricing): CouponRedemption
    {
        return DB::transaction(function () use ($payment, $coupon, $context, $pricing) {
            /** @var Coupon $lockedCoupon */
            $lockedCoupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            $this->coupons->assertEligible($lockedCoupon, $context, checkUsageLimits: true);

            if ($lockedCoupon->max_total_uses !== null && (int) ($lockedCoupon->used_count ?? 0) >= (int) $lockedCoupon->max_total_uses) {
                throw ValidationException::withMessages([
                    'coupon' => __('This coupon has reached its usage limit.'),
                ]);
            }

            $lockedCoupon->increment('used_count');

            return CouponRedemption::query()->create([
                'coupon_id' => $lockedCoupon->id,
                'customer_id' => $context->customer->id,
                'payment_id' => $payment->id,
                'purchase_type' => $context->purchaseType,
                'purchasable_type' => $context->purchasableType,
                'purchasable_id' => $context->purchasableId,
                'item_code' => $context->itemCode,
                'billing_cycle' => $context->billingCycle,
                'cycle_index' => $context->cycleIndex,
                'redemption_type' => CouponRedemptionType::CHECKOUT,
                'status' => CouponRedemptionStatus::RESERVED,
                'coupon_code' => (string) $lockedCoupon->code,
                'original_amount_iqd' => (int) ($pricing['original_amount_iqd'] ?? 0),
                'discount_amount_iqd' => (int) ($pricing['discount_amount_iqd'] ?? 0),
                'final_amount_iqd' => (int) ($pricing['final_amount_iqd'] ?? 0),
                'discount_snapshot' => $pricing,
                'metadata' => [
                    'reserved_at' => now()->toIso8601String(),
                ],
            ]);
        }, 3);
    }

    public function markApplied(Payment $payment): void
    {
        $redemption = $this->checkoutRedemption($payment);

        if (! $redemption instanceof CouponRedemption || $redemption->status !== CouponRedemptionStatus::RESERVED) {
            return;
        }

        $redemption->forceFill([
            'status' => CouponRedemptionStatus::APPLIED,
            'metadata' => array_merge((array) $redemption->metadata, [
                'applied_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    public function consumeForPayment(Payment $payment): void
    {
        $redemption = $this->checkoutRedemption($payment);

        if (! $redemption instanceof CouponRedemption
            || ! in_array($redemption->status, [CouponRedemptionStatus::RESERVED, CouponRedemptionStatus::APPLIED], true)) {
            return;
        }

        $redemption->forceFill([
            'status' => CouponRedemptionStatus::CONSUMED,
            'metadata' => array_merge((array) $redemption->metadata, [
                'consumed_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    public function releaseForPayment(Payment $payment, string $reason = 'payment_released'): void
    {
        $redemption = $this->checkoutRedemption($payment);

        if (! $redemption instanceof CouponRedemption
            || ! in_array($redemption->status, [CouponRedemptionStatus::RESERVED, CouponRedemptionStatus::APPLIED], true)) {
            return;
        }

        DB::transaction(function () use ($redemption, $reason) {
            /** @var CouponRedemption $lockedRedemption */
            $lockedRedemption = CouponRedemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if (! in_array($lockedRedemption->status, [CouponRedemptionStatus::RESERVED, CouponRedemptionStatus::APPLIED], true)) {
                return;
            }

            /** @var Coupon $coupon */
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($lockedRedemption->coupon_id);

            $lockedRedemption->forceFill([
                'status' => CouponRedemptionStatus::RELEASED,
                'metadata' => array_merge((array) $lockedRedemption->metadata, [
                    'released_at' => now()->toIso8601String(),
                    'release_reason' => $reason,
                ]),
            ])->save();

            $coupon->forceFill([
                'used_count' => max(0, (int) ($coupon->used_count ?? 0) - 1),
            ])->save();
        }, 3);
    }

    protected function checkoutRedemption(Payment $payment): ?CouponRedemption
    {
        return CouponRedemption::query()
            ->where('payment_id', $payment->id)
            ->where('redemption_type', CouponRedemptionType::CHECKOUT)
            ->latest('id')
            ->first();
    }
}

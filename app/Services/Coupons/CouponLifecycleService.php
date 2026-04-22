<?php

namespace App\Services\Coupons;

use App\Enums\CouponDurationType;
use App\Models\Coupon;

class CouponLifecycleService
{
    public function appliesToCycle(Coupon $coupon, int $cycleIndex): bool
    {
        $cycleIndex = max(1, $cycleIndex);
        $durationType = $coupon->duration_type ?? CouponDurationType::ONCE;

        return match ($durationType) {
            CouponDurationType::ONCE, CouponDurationType::FIRST_CYCLE => $cycleIndex === 1,
            CouponDurationType::FIRST_N_CYCLES => $cycleIndex <= max(1, (int) ($coupon->duration_cycles ?? 1)),
            CouponDurationType::FOREVER => true,
        };
    }

    public function maximumDiscountedCycles(Coupon $coupon): ?int
    {
        return match ($coupon->duration_type ?? CouponDurationType::ONCE) {
            CouponDurationType::ONCE, CouponDurationType::FIRST_CYCLE => 1,
            CouponDurationType::FIRST_N_CYCLES => max(1, (int) ($coupon->duration_cycles ?? 1)),
            CouponDurationType::FOREVER => null,
        };
    }
}

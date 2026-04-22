<?php

namespace App\Enums;

use App\Domain\Payments\Enums\PurchaseType;

enum CouponTargetType: string
{
    case ALL = 'all';
    case PLAN_SUBSCRIPTION = 'plan_subscription';
    case STORAGE_SUBSCRIPTION = 'storage_subscription';
    case ADDON_CREDITS = 'addon_credits';

    public function supports(PurchaseType $purchaseType): bool
    {
        return match ($this) {
            self::ALL => true,
            self::PLAN_SUBSCRIPTION => $purchaseType === PurchaseType::PLAN_SUBSCRIPTION,
            self::STORAGE_SUBSCRIPTION => $purchaseType === PurchaseType::STORAGE_SUBSCRIPTION,
            self::ADDON_CREDITS => $purchaseType === PurchaseType::ADDON_CREDITS,
        };
    }
}

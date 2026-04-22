<?php

namespace App\Services\Coupons;

use App\Domain\Payments\Enums\PurchaseType;
use App\Models\Customer;

final class CouponContext
{
    public function __construct(
        public readonly Customer $customer,
        public readonly PurchaseType $purchaseType,
        public readonly string $provider,
        public readonly string $purchasableType,
        public readonly int $purchasableId,
        public readonly string $itemCode,
        public readonly int $originalAmountIqd,
        public readonly ?string $billingCycle = null,
        public readonly bool $isRecurring = false,
        public readonly int $cycleIndex = 1,
    ) {
    }
}

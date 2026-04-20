<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentStatus;

class FibSubscriptionMapper
{
    public function toLocalStatus(FibSubscriptionStatusData $status): PaymentStatus
    {
        $normalized = strtoupper(trim($status->status));

        if ($normalized === 'PAID' || $normalized === 'ACTIVE' || $normalized === 'SUBSCRIBED') {
            return PaymentStatus::PAID;
        }

        if (str_contains($normalized, 'UNPAID') || in_array($normalized, ['PENDING', 'CREATED', 'INITIATED'], true)) {
            return PaymentStatus::AWAITING_CUSTOMER_ACTION;
        }

        if (in_array($normalized, ['CANCELED', 'CANCELLED'], true)) {
            return PaymentStatus::CANCELED;
        }

        if (in_array($normalized, ['EXPIRED', 'TIMED_OUT'], true)) {
            return PaymentStatus::EXPIRED;
        }

        if (in_array($normalized, ['DECLINED', 'REJECTED', 'FAILED'], true)) {
            return PaymentStatus::FAILED;
        }

        if ($status->lastPaymentAt !== null || $status->activeUntil !== null) {
            return PaymentStatus::PAID;
        }

        return PaymentStatus::AWAITING_CUSTOMER_ACTION;
    }

    public function normalizeStatus(?string $status): ?string
    {
        $status = is_string($status) ? trim($status) : '';

        return $status === '' ? null : strtoupper($status);
    }
}

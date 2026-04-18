<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\PaymentStatus;

final class PaymentTransitions
{
    public static function canTransition(PaymentStatus $current, PaymentStatus $next): bool
    {
        if ($current === $next) {
            return true;
        }

        return match ($current) {
            PaymentStatus::PENDING => in_array($next, [
                PaymentStatus::AWAITING_CUSTOMER_ACTION,
                PaymentStatus::PAID,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELED,
                PaymentStatus::EXPIRED,
            ], true),
            PaymentStatus::AWAITING_CUSTOMER_ACTION => in_array($next, [
                PaymentStatus::PAID,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELED,
                PaymentStatus::EXPIRED,
            ], true),
            PaymentStatus::PAID => in_array($next, [
                PaymentStatus::REFUND_REQUESTED,
                PaymentStatus::REFUNDED,
            ], true),
            PaymentStatus::REFUND_REQUESTED => $next === PaymentStatus::REFUNDED,
            default => false,
        };
    }
}

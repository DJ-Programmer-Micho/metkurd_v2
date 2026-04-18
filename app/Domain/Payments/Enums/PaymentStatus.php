<?php

namespace App\Domain\Payments\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case AWAITING_CUSTOMER_ACTION = 'awaiting_customer_action';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    case REFUND_REQUESTED = 'refund_requested';
    case REFUNDED = 'refunded';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::PAID,
            self::FAILED,
            self::CANCELED,
            self::EXPIRED,
            self::REFUNDED,
        ], true);
    }
}

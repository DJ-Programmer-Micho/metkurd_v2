<?php

namespace App\Domain\Payments\Enums;

enum PaymentInternalStatus: string
{
    case PENDING = 'pending';
    case AWAITING_CUSTOMER_ACTION = 'awaiting_customer_action';
    case PAID_PENDING_APPLICATION = 'paid_pending_application';
    case APPLIED = 'applied';
    case REQUIRES_REVIEW = 'requires_review';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    case REFUND_REQUESTED = 'refund_requested';
    case REFUNDED = 'refunded';

    public static function fromPaymentStatus(
        PaymentStatus $status,
        bool $fulfilled = false,
        bool $requiresReview = false,
    ): self {
        if ($fulfilled) {
            return self::APPLIED;
        }

        if ($requiresReview) {
            return self::REQUIRES_REVIEW;
        }

        return match ($status) {
            PaymentStatus::PENDING => self::PENDING,
            PaymentStatus::AWAITING_CUSTOMER_ACTION => self::AWAITING_CUSTOMER_ACTION,
            PaymentStatus::PAID => self::PAID_PENDING_APPLICATION,
            PaymentStatus::FAILED => self::FAILED,
            PaymentStatus::CANCELED => self::CANCELED,
            PaymentStatus::EXPIRED => self::EXPIRED,
            PaymentStatus::REFUND_REQUESTED => self::REFUND_REQUESTED,
            PaymentStatus::REFUNDED => self::REFUNDED,
        };
    }

    public function isFinalForCustomer(): bool
    {
        return in_array($this, [
            self::APPLIED,
            self::REQUIRES_REVIEW,
            self::FAILED,
            self::CANCELED,
            self::EXPIRED,
            self::REFUNDED,
        ], true);
    }
}

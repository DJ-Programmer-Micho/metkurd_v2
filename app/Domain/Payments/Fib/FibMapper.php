<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Enums\PaymentStatus;

class FibMapper
{
    public function toLocalStatus(FibPaymentStatusData $status): PaymentStatus
    {
        return match ($status->status) {
            'PAID' => PaymentStatus::PAID,
            'UNPAID' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
            'REFUND_REQUESTED' => PaymentStatus::REFUND_REQUESTED,
            'REFUNDED' => PaymentStatus::REFUNDED,
            'DECLINED' => $this->declinedStatus($status->decliningReason),
            default => PaymentStatus::FAILED,
        };
    }

    public function normalizeDecliningReason(?string $reason): ?string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return $reason === '' ? null : strtoupper($reason);
    }

    protected function declinedStatus(?string $reason): PaymentStatus
    {
        return match ($this->normalizeDecliningReason($reason)) {
            'PAYMENT_CANCELLATION' => PaymentStatus::CANCELED,
            'PAYMENT_EXPIRATION' => PaymentStatus::EXPIRED,
            default => PaymentStatus::FAILED,
        };
    }
}

<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentStatus;

class FibSubscriptionMapper
{
    public function toLocalStatus(FibSubscriptionStatusData $status): PaymentStatus
    {
        $normalized = $this->normalizeStatus($status->status) ?? 'PENDING';

        if ($this->isExplicitlyPaidStatus($normalized)) {
            return PaymentStatus::PAID;
        }

        if ($this->isPaidLifecycleStatus($normalized)) {
            return $this->hasConfirmedPayment($status)
                ? PaymentStatus::PAID
                : PaymentStatus::AWAITING_CUSTOMER_ACTION;
        }

        if ($this->isAwaitingStatus($normalized)) {
            return PaymentStatus::AWAITING_CUSTOMER_ACTION;
        }

        if ($this->isCanceledStatus($normalized)) {
            return PaymentStatus::CANCELED;
        }

        if ($this->isExpiredStatus($normalized)) {
            return PaymentStatus::EXPIRED;
        }

        if ($this->isFailedStatus($normalized)) {
            return PaymentStatus::FAILED;
        }

        if ($this->hasConfirmedPayment($status)) {
            return PaymentStatus::PAID;
        }

        return PaymentStatus::AWAITING_CUSTOMER_ACTION;
    }

    public function normalizeStatus(?string $status): ?string
    {
        $status = is_string($status) ? trim($status) : '';

        return $status === '' ? null : strtoupper($status);
    }

    protected function hasConfirmedPayment(FibSubscriptionStatusData $status): bool
    {
        return $status->lastPaymentAt !== null;
    }

    protected function isExplicitlyPaidStatus(string $status): bool
    {
        return in_array($status, ['PAID', 'APPROVED', 'CONFIRMED', 'CAPTURED', 'SETTLED', 'SUCCESS'], true);
    }

    protected function isPaidLifecycleStatus(string $status): bool
    {
        return in_array($status, ['ACTIVE', 'SUBSCRIBED'], true);
    }

    protected function isAwaitingStatus(string $status): bool
    {
        return str_contains($status, 'UNPAID')
            || in_array($status, ['PENDING', 'CREATED', 'INITIATED', 'PROCESSING'], true);
    }

    protected function isCanceledStatus(string $status): bool
    {
        return in_array($status, ['CANCELED', 'CANCELLED'], true);
    }

    protected function isExpiredStatus(string $status): bool
    {
        return in_array($status, ['EXPIRED', 'TIMED_OUT'], true);
    }

    protected function isFailedStatus(string $status): bool
    {
        return in_array($status, ['DECLINED', 'REJECTED', 'FAILED', 'ERROR'], true);
    }
}

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
        if ($status->lastPaymentAt !== null) {
            return true;
        }

        if ($this->hasPositivePaidFlagFromRaw($status)) {
            return true;
        }

        return $this->canInferInitialChargeFromActiveStatus($status);
    }

    protected function isExplicitlyPaidStatus(string $status): bool
    {
        return in_array($status, ['PAID', 'APPROVED', 'CONFIRMED', 'CAPTURED', 'SETTLED', 'SUCCESS'], true);
    }

    protected function isPaidLifecycleStatus(string $status): bool
    {
        return in_array($status, ['ACTIVE', 'SUBSCRIBED'], true);
    }

    protected function hasPositivePaidFlagFromRaw(FibSubscriptionStatusData $status): bool
    {
        $raw = $status->raw;

        foreach ([
            data_get($raw, 'isPaid'),
            data_get($raw, 'paid'),
            data_get($raw, 'paymentCompleted'),
            data_get($raw, 'isPaymentCompleted'),
            data_get($raw, 'latestPayment.isPaid'),
            data_get($raw, 'latestPayment.paid'),
        ] as $flag) {
            if (is_bool($flag) && $flag) {
                return true;
            }
        }

        return false;
    }

    protected function canInferInitialChargeFromActiveStatus(FibSubscriptionStatusData $status): bool
    {
        $normalizedStatus = $this->normalizeStatus($status->status);

        if (! in_array($normalizedStatus, ['ACTIVE', 'SUBSCRIBED'], true)) {
            return false;
        }

        // Keep trial subscriptions in awaiting state until explicit payment evidence exists.
        if ($this->hasNonZeroTrialPeriod($status->trialPeriod)) {
            return false;
        }

        return (int) data_get($status->amount, 'amount', 0) > 0;
    }

    protected function hasNonZeroTrialPeriod(?string $trialPeriod): bool
    {
        $trialPeriod = strtoupper(trim((string) $trialPeriod));

        if ($trialPeriod === '') {
            return false;
        }

        return ! in_array($trialPeriod, ['P0D', 'PT0S', '0', 'NONE'], true);
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

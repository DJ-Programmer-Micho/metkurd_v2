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
            return $this->hasConfirmedPaymentEvidence($status)
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

        if ($this->hasConfirmedPaymentEvidence($status)) {
            return PaymentStatus::PAID;
        }

        return PaymentStatus::AWAITING_CUSTOMER_ACTION;
    }

    public function normalizeStatus(?string $status): ?string
    {
        $status = is_string($status) ? trim($status) : '';

        return $status === '' ? null : strtoupper($status);
    }

    public function hasConfirmedPaymentEvidence(FibSubscriptionStatusData $status, ?array ...$payloads): bool
    {
        if ($status->lastPaymentAt !== null) {
            return true;
        }

        if ($this->paidStatusFromPayload($status->raw) !== null) {
            return true;
        }

        if ($this->hasPositivePaidFlagFromPayload($status->raw)) {
            return true;
        }

        foreach ($payloads as $payload) {
            if (! is_array($payload) || $payload === []) {
                continue;
            }

            if ($this->paidStatusFromPayload($payload) !== null) {
                return true;
            }

            if ($this->hasPositivePaidFlagFromPayload($payload)) {
                return true;
            }
        }

        return false;
    }

    public function explicitPaidStatusFromPayloads(FibSubscriptionStatusData $status, ?array ...$payloads): ?string
    {
        $statusValue = $this->paidStatusFromPayload($status->raw);

        if ($statusValue !== null) {
            return $statusValue;
        }

        foreach ($payloads as $payload) {
            if (! is_array($payload) || $payload === []) {
                continue;
            }

            $statusValue = $this->paidStatusFromPayload($payload);

            if ($statusValue !== null) {
                return $statusValue;
            }
        }

        return null;
    }

    public function isPaidLifecycleStatusValue(?string $status): bool
    {
        $normalized = $this->normalizeStatus($status);

        return $normalized !== null && $this->isPaidLifecycleStatus($normalized);
    }

    protected function hasConfirmedPayment(FibSubscriptionStatusData $status): bool
    {
        return $this->hasConfirmedPaymentEvidence($status);
    }

    protected function hasPositivePaidFlagFromPayload(array $payload): bool
    {
        foreach ([
            data_get($payload, 'isPaid'),
            data_get($payload, 'paid'),
            data_get($payload, 'paymentCompleted'),
            data_get($payload, 'isPaymentCompleted'),
            data_get($payload, 'latestPayment.isPaid'),
            data_get($payload, 'latestPayment.paid'),
            data_get($payload, 'payment.isPaid'),
            data_get($payload, 'payment.paid'),
        ] as $flag) {
            if (is_bool($flag) && $flag) {
                return true;
            }
        }

        return false;
    }

    protected function paidStatusFromPayload(array $payload): ?string
    {
        foreach ([
            data_get($payload, 'paymentStatus'),
            data_get($payload, 'payment.status'),
            data_get($payload, 'latestPayment.status'),
            data_get($payload, 'latestPayment.paymentStatus'),
            data_get($payload, 'subscription.paymentStatus'),
        ] as $candidate) {
            $normalized = $this->normalizeStatus(is_scalar($candidate) ? (string) $candidate : null);

            if ($normalized !== null && $this->isExplicitlyPaidStatus($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    protected function hasPositivePaidFlagFromRaw(FibSubscriptionStatusData $status): bool
    {
        if ($this->paidStatusFromPayload($status->raw) !== null) {
            return true;
        }

        return $this->hasPositivePaidFlagFromPayload($status->raw);
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

    protected function isExplicitlyPaidStatus(string $status): bool
    {
        return in_array($status, ['PAID', 'APPROVED', 'CONFIRMED', 'CAPTURED', 'SETTLED', 'SUCCESS'], true);
    }

    protected function isPaidLifecycleStatus(string $status): bool
    {
        return in_array($status, ['ACTIVE', 'SUBSCRIBED'], true);
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

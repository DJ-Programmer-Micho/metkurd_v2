<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;

class PaymentReconciliationPolicy
{
    public function checkoutCandidateBucket(Payment $payment): string
    {
        if ($this->isApplied($payment)) {
            return 'applied';
        }

        if ($this->requiresReview($payment)) {
            return 'review';
        }

        if ($this->canScheduledCheckoutPoll($payment)) {
            return 'unresolved';
        }

        return 'terminal';
    }

    public function canScheduledCheckoutPoll(Payment $payment): bool
    {
        if ($this->isApplied($payment) || $this->requiresReview($payment) || $this->hasTerminalCheckoutState($payment)) {
            return false;
        }

        if ($this->hasProviderActiveWithoutPeriodEnd($payment)) {
            return false;
        }

        return $this->hasUnresolvedCheckoutState($payment);
    }

    public function canScheduledRenewalPoll(Payment $payment): bool
    {
        if (! $this->isFibRecurringSubscription($payment)) {
            return false;
        }

        if ($this->requiresReview($payment) || $this->hasTerminalCheckoutState($payment)) {
            return false;
        }

        if ($this->rawPaymentStatus($payment) !== PaymentStatus::PAID->value || ! $this->isApplied($payment)) {
            return false;
        }

        if (trim((string) $payment->fib_subscription_id) === '') {
            return false;
        }

        return $this->hasActiveEntitlement($payment)
            || $this->hasFutureActiveUntil($payment)
            || $this->hasProviderActiveWithoutPeriodEnd($payment);
    }

    public function shouldSkipProviderFailureEvent(Payment $payment, string $source, string $eventType): bool
    {
        return match ($eventType) {
            'provider_status_sync_failed' => ! $this->canScheduledCheckoutPoll($payment),
            'provider_renewal_sync_failed' => ! $this->canScheduledRenewalPoll($payment),
            default => false,
        };
    }

    public function normalizeScheduledSource(Payment $payment, string $source, ?string $eventType = null): string
    {
        $source = trim($source);

        if ($source !== 'scheduled_reconciliation') {
            return $source;
        }

        if ($eventType === 'provider_renewal_sync_failed'
            || (! $this->canScheduledCheckoutPoll($payment) && $this->canScheduledRenewalPoll($payment))) {
            return 'scheduled_subscription_renewal_reconciliation';
        }

        return $payment->isProviderSubscriptionObject()
            ? 'scheduled_subscription_checkout_reconciliation'
            : 'scheduled_payment_reconciliation';
    }

    public function isScheduledCheckoutSource(string $source): bool
    {
        return in_array(trim($source), [
            'scheduled_reconciliation',
            'scheduled_payment_reconciliation',
            'scheduled_subscription_checkout_reconciliation',
        ], true);
    }

    public function isScheduledRenewalSource(string $source): bool
    {
        return trim($source) === 'scheduled_subscription_renewal_reconciliation';
    }

    protected function isApplied(Payment $payment): bool
    {
        return $payment->fulfilled_at !== null
            || $payment->internal_status === PaymentInternalStatus::APPLIED
            || in_array($this->rawInternalStatus($payment), ['applied', 'fulfilled'], true);
    }

    protected function requiresReview(Payment $payment): bool
    {
        return $payment->review_required_at !== null
            || $payment->internal_status === PaymentInternalStatus::REQUIRES_REVIEW
            || $this->rawInternalStatus($payment) === PaymentInternalStatus::REQUIRES_REVIEW->value;
    }

    protected function hasTerminalCheckoutState(Payment $payment): bool
    {
        return in_array($this->rawPaymentStatus($payment), [
            PaymentStatus::FAILED->value,
            PaymentStatus::CANCELED->value,
            PaymentStatus::EXPIRED->value,
            PaymentStatus::REFUND_REQUESTED->value,
            PaymentStatus::REFUNDED->value,
            'declined',
        ], true) || in_array($this->rawInternalStatus($payment), [
            PaymentInternalStatus::FAILED->value,
            PaymentInternalStatus::CANCELED->value,
            PaymentInternalStatus::EXPIRED->value,
            PaymentInternalStatus::REFUND_REQUESTED->value,
            PaymentInternalStatus::REFUNDED->value,
        ], true);
    }

    protected function hasUnresolvedCheckoutState(Payment $payment): bool
    {
        $status = $this->rawPaymentStatus($payment);
        $internalStatus = $this->rawInternalStatus($payment);

        if (in_array($status, [
            PaymentStatus::PENDING->value,
            PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
        ], true)) {
            return true;
        }

        return $status === PaymentStatus::PAID->value
            && $payment->fulfilled_at === null
            && in_array($internalStatus, [
                null,
                '',
                PaymentInternalStatus::PENDING->value,
                PaymentInternalStatus::AWAITING_CUSTOMER_ACTION->value,
                PaymentInternalStatus::PAID_PENDING_APPLICATION->value,
            ], true);
    }

    protected function isFibRecurringSubscription(Payment $payment): bool
    {
        return $payment->provider === PaymentProvider::FIB
            && ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT) === PaymentProviderObjectType::SUBSCRIPTION
            && $payment->resolvedPaymentMode(PaymentMode::ONE_TIME)->isRecurring();
    }

    protected function hasProviderActiveWithoutPeriodEnd(Payment $payment): bool
    {
        if (! $this->isFibRecurringSubscription($payment)) {
            return false;
        }

        return $payment->active_until === null
            && in_array(strtoupper(trim((string) ($payment->providerStatusLabel() ?? ''))), ['ACTIVE', 'PAID', 'SUBSCRIBED'], true);
    }

    protected function hasFutureActiveUntil(Payment $payment): bool
    {
        return $payment->active_until instanceof \DateTimeInterface
            && $payment->active_until >= now();
    }

    protected function hasActiveEntitlement(Payment $payment): bool
    {
        return $this->hasActiveServiceEntitlement($payment) || $this->hasActiveStorageEntitlement($payment);
    }

    protected function hasActiveServiceEntitlement(Payment $payment): bool
    {
        if ($payment->relationLoaded('serviceSubscriptions')) {
            return $payment->serviceSubscriptions->contains(fn (mixed $subscription): bool => data_get($subscription, 'status') === 'active');
        }

        return $payment->serviceSubscriptions()->where('status', 'active')->exists();
    }

    protected function hasActiveStorageEntitlement(Payment $payment): bool
    {
        if ($payment->relationLoaded('storageSubscriptions')) {
            return $payment->storageSubscriptions->contains(fn (mixed $subscription): bool => data_get($subscription, 'status') === 'active');
        }

        return $payment->storageSubscriptions()->where('status', 'active')->exists();
    }

    protected function rawPaymentStatus(Payment $payment): ?string
    {
        $value = trim((string) $payment->getRawOriginal('status'));

        return $value !== '' ? strtolower($value) : null;
    }

    protected function rawInternalStatus(Payment $payment): ?string
    {
        $value = trim((string) $payment->getRawOriginal('internal_status'));

        return $value !== '' ? strtolower($value) : null;
    }
}

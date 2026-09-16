<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Models\Payment;

final class FibStatusEvidence
{
    /** Returns a safe reason code, never a provider payload or banking value. */
    public function rejection(Payment $payment, FibPaymentStatusData|FibSubscriptionStatusData $status): ?string
    {
        $subscription = $status instanceof FibSubscriptionStatusData;
        $expected = (string) ($subscription ? $payment->fib_subscription_id : $payment->fib_payment_id);
        $returned = $subscription ? $status->subscriptionId : $status->paymentId;
        if ($payment->provider !== PaymentProvider::FIB || $expected === '' || $returned !== $expected) {
            return 'object_identity_mismatch';
        }
        foreach ($subscription ? ['id', 'subscriptionId'] : ['paymentId'] as $key) {
            if (array_key_exists($key, $status->raw) && $status->raw[$key] !== $expected) {
                return 'object_identity_mismatch';
            }
        }
        // Inspect original optional fields: DTO defaults/casts must not hide malformed money.
        foreach (['monetaryValue', 'amount'] as $key) {
            if (! array_key_exists($key, $status->raw)) {
                continue;
            }
            $money = $status->raw[$key];
            if (! is_array($money) || ! is_numeric($money['amount'] ?? null)
                || (float) $money['amount'] !== (float) $payment->amount
                || ! is_string($money['currency'] ?? null)
                || strtoupper(trim($money['currency'])) !== strtoupper($payment->currency)) {
                return 'monetary_value_mismatch';
            }
        }
        foreach (['localReference', 'merchantTransactionId'] as $key) {
            if (array_key_exists($key, $status->raw) && $status->raw[$key] !== $payment->local_reference) {
                return 'merchant_reference_mismatch';
            }
        }
        if (! $subscription) {
            return null;
        }
        foreach (['validUntil', 'activeUntil', 'lastPaymentAt', 'lastPaidAt', 'lastSuccessfulPaymentAt', 'latestPaidAt',
            'payment.lastPaymentAt', 'payment.lastPaidAt', 'latestPayment.lastPaymentAt', 'latestPayment.lastPaidAt',
            'subscription.lastPaymentAt', 'subscription.lastPaidAt'] as $key) {
            $value = data_get($status->raw, $key);
            if ($value !== null && FibSubscriptionTimestamp::parse($value) === null) {
                return 'invalid_subscription_timestamp';
            }
        }
        // Cancellation/terminal transitions may end coverage, but never replace last payment with an older value.
        // Existing lifecycle closed states; validation stays independent of transport.
        $terminal = in_array($status->status, ['CANCELED', 'CANCELLED', 'EXPIRED', 'TIMED_OUT', 'DECLINED', 'REJECTED', 'FAILED', 'INACTIVE', 'ENDED'], true);
        if (! $terminal && (($status->lastPaymentAt && $payment->last_payment_at && $status->lastPaymentAt->lt($payment->last_payment_at))
            || ($status->activeUntil && $payment->active_until && $status->activeUntil->lt($payment->active_until)))) {
            return 'stale_subscription_observation';
        }

        return null;
    }
}

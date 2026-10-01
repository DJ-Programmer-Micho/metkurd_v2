<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;

final class FibStatusEvidence
{
    /**
     * Read the latest persisted authenticated GET, never a callback or a local status.
     * Current receipts advance without appending events for unchanged observations.
     * Legacy rows retain strict raw response/event matching.
     *
     * @return array{status: FibSubscriptionStatusData, event_id: int|null}|null
     */
    public function persistedSubscriptionObservation(Payment $payment): ?array
    {
        if (! $payment->isProviderSubscriptionObject() || $payment->provider !== PaymentProvider::FIB
            || ! $payment->fib_subscription_id || ! $payment->last_status_checked_at
            || $payment->last_status_checked_at->isFuture()) {
            return null;
        }
        // Select before validating: never fall back past newer contradictory/bad evidence.
        $event = PaymentEvent::where('payment_id', $payment->id)
            ->whereIn('event_type', [...ProviderObservation::EVENTS, 'provider_status_checked', 'provider_status_ignored'])
            ->latest('id')->first();

        $callbackId = (int) PaymentEvent::where('payment_id', $payment->id)->where('event_type', 'callback_received')->max('id');

        return $this->validatePersistedObservation($payment, $event, $callbackId > ($event?->id ?? 0), $callbackId);
    }

    /** Same validator for a bulk-loaded snapshot; this method performs no queries. */
    public function validatePersistedObservation(Payment $payment, ?PaymentEvent $event, bool $newerCallback, ?int $callbackId = null): ?array
    {
        if (! $payment->isProviderSubscriptionObject() || $payment->provider !== PaymentProvider::FIB
            || ! $payment->fib_subscription_id || ! $payment->last_status_checked_at || $payment->last_status_checked_at->isFuture()) {
            return null;
        }
        if (data_get($payment->meta, 'provider_observation') !== null) {
            if (! ProviderObservation::validReceipt($payment, $event)
                || ($newerCallback && ($callbackId === null || $callbackId > (int) data_get($payment->meta, 'provider_observation.observed_event_watermark', 0)))) {
                return null;
            }
            $status = FibSubscriptionStatusData::fromArray((array) $payment->status_response);
            $requestedAt = FibSubscriptionTimestamp::parse(data_get($payment->meta, 'provider_cancellation.requested_at'));
            if ($this->rejection($payment, $status) !== null
                || $status->status !== strtoupper((string) $payment->provider_subscription_status)
                || ($status->lastPaymentAt && ($status->lastPaymentAt->isFuture()
                    || ($status->activeUntil && ! $status->activeUntil->gt($status->lastPaymentAt))))
                || ($requestedAt && $requestedAt->gt($payment->last_status_checked_at))) {
                return null;
            }

            return ['status' => $status, 'event_id' => $event?->id];
        }
        if (ProviderObservation::pending($payment)) {
            return null;
        }
        if (! $event || (int) $event->payment_id !== (int) $payment->id
            || ! in_array($event->event_type, ['provider_status_checked', 'provider_status_ignored'], true)
            || $event->provider !== 'fib' || $event->provider_object_type !== 'subscription'
            || $event->fib_subscription_id !== $payment->fib_subscription_id
            || $event->local_reference !== $payment->local_reference
            || ! $event->processed_at || $event->processed_at->isFuture()
            || $event->processed_at->timestamp !== $payment->last_status_checked_at->timestamp
            || ! is_array($event->payload) || $event->payload !== $payment->status_response
            || data_get($event->meta, 'provider_object_type') !== 'subscription') {
            return null;
        }
        $status = FibSubscriptionStatusData::fromArray($event->payload);
        if ($this->rejection($payment, $status) !== null
            || $status->status !== strtoupper((string) $payment->provider_subscription_status)
            || ($status->lastPaymentAt && ($status->lastPaymentAt->isFuture()
                || ($status->activeUntil && ! $status->activeUntil->gt($status->lastPaymentAt))))) {
            return null;
        }
        // A subsequent unverified callback cannot prove a transition, but it does
        // prevent an older GET from settling a potentially changed obligation.
        if ($newerCallback) {
            return null;
        }
        $requestedAt = FibSubscriptionTimestamp::parse(data_get($payment->meta, 'provider_cancellation.requested_at'));
        if ($requestedAt && $requestedAt->gt($event->processed_at)) {
            return null;
        }

        return ['status' => $status, 'event_id' => (int) $event->id];
    }

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

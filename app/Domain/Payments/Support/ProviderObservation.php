<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Services\Billing\ProviderReviewSnapshot;

/** Latest GET receipt is operational state; immutable events describe meaningful transitions. */
final class ProviderObservation
{
    public const EVENTS = ['provider_status_changed', 'provider_collection_verified', 'provider_evidence_changed'];

    public static function callbackVersion(Payment $payment): int
    {
        return (int) data_get($payment->meta, 'provider_callback_version', 0);
    }

    public static function state(Payment $payment): array
    {
        return ['provider_reference' => $payment->providerReference(), 'object_type' => $payment->provider_object_type?->value,
            'provider_status' => strtoupper((string) $payment->providerStatusLabel()),
            'payment_status' => strtoupper((string) $payment->providerPaymentStatusLabel()),
            'local_status' => $payment->status?->value,
            'last_payment_at' => $payment->last_payment_at?->toIso8601String(),
            'active_until' => $payment->active_until?->toIso8601String(),
            'unmapped_collection_evidence' => (bool) data_get(PaymentPersistence::status($payment->status_response), 'unmapped_collection_evidence', false),
            'paid_at' => $payment->paid_at?->toIso8601String(), 'declining_reason' => $payment->declining_reason];
    }

    /** Called inside the status writer's Payment lock/transaction, after live GET validation. */
    public static function record(Payment $payment, array $before, string $source, int $callbackVersion, int $eventWatermark, array $context = []): void
    {
        $after = self::state($payment);
        $meta = (array) $payment->meta;
        $revision = (int) data_get($meta, 'provider_observation.revision', 0);
        $eventId = data_get($meta, 'provider_observation.event_id');
        if (! $eventId) {
            $eventId = $payment->events()->whereIn('event_type', [...self::EVENTS, 'provider_status_checked', 'provider_status_ignored'])->latest('id')->value('id');
        }
        $eventHash = data_get($meta, 'provider_observation.event_hash');
        // First verified terminal evidence is meaningful even for an already-terminal legacy row.
        if ($before !== $after || (! $eventId && in_array($after['provider_status'], ['CANCELLED', 'CANCELED', 'REJECTED'], true))) {
            $revision++;
            $type = $before['provider_status'] !== $after['provider_status'] || $before['local_status'] !== $after['local_status']
                ? 'provider_status_changed'
                : ($before['last_payment_at'] !== $after['last_payment_at'] && $after['last_payment_at'] !== null
                    ? 'provider_collection_verified' : 'provider_evidence_changed');
            $event = app(PaymentEventRecorder::class)->record($payment, [
                'event_type' => $type, 'source' => $source,
                'event_key' => 'provider-transition:'.$payment->id.':'.ProviderReviewSnapshot::hash([$revision, $before, $after]),
                'before_status' => $before['local_status'], 'after_status' => $after['local_status'],
                'payload' => PaymentPersistence::status($payment->status_response),
                'meta' => ['provider_object_type' => $after['object_type'], 'revision' => $revision,
                    'previous_provider_status' => $before['provider_status'], 'provider_status' => $after['provider_status']] + $context,
            ]);
            $eventId = $event->id;
            $eventHash = self::eventHash($event);
        }
        if ($eventId && ! $eventHash) {
            $anchor = PaymentEvent::find($eventId);
            $eventHash = $anchor ? self::eventHash($anchor) : null;
        }
        $meta['provider_observation'] = ['version' => 1, 'valid' => true, 'revision' => $revision,
            'event_id' => $eventId ? (int) $eventId : null, 'event_hash' => $eventHash, 'provider_reference' => $payment->providerReference(),
            'object_type' => $payment->provider_object_type?->value, 'local_reference' => $payment->local_reference,
            'payload_hash' => ProviderReviewSnapshot::hash($payment->status_response),
            'checked_at' => $payment->last_status_checked_at?->toIso8601String(), 'callback_version' => $callbackVersion,
            'observed_event_watermark' => $eventWatermark];
        $payment->forceFill(['meta' => $meta])->save();
    }

    /** No fallback to older evidence after an unverified callback, failure or rejected GET. */
    public static function pending(Payment $payment): bool
    {
        $receipt = data_get($payment->meta, 'provider_observation');

        return ($receipt !== null && ! ($receipt['valid'] ?? false))
            || self::callbackVersion($payment) !== (int) ($receipt['callback_version'] ?? 0);
    }

    public static function validReceipt(Payment $payment, ?PaymentEvent $event): bool
    {
        $receipt = data_get($payment->meta, 'provider_observation', []);
        $checked = FibSubscriptionTimestamp::parse($receipt['checked_at'] ?? null);
        if (($receipt['version'] ?? null) !== 1 || self::pending($payment)
            || ! $checked || $checked->isFuture() || ! $payment->last_status_checked_at
            || $checked->timestamp !== $payment->last_status_checked_at->timestamp
            || ($receipt['provider_reference'] ?? null) !== $payment->providerReference()
            || ($receipt['local_reference'] ?? null) !== $payment->local_reference
            || ($receipt['object_type'] ?? null) !== $payment->provider_object_type?->value
            || ($receipt['payload_hash'] ?? null) !== ProviderReviewSnapshot::hash($payment->status_response)) {
            return false;
        }
        if (($receipt['event_id'] ?? null) !== null) {
            return $event && (int) $receipt['event_id'] === (int) $event->id
                && (int) $event->payment_id === (int) $payment->id && $event->provider === 'fib'
                && $event->provider_object_type === $payment->provider_object_type?->value
                && $event->fib_subscription_id === $payment->fib_subscription_id
                && $event->fib_payment_id === $payment->fib_payment_id
                && $event->local_reference === $payment->local_reference
                && $event->processed_at && ! $event->processed_at->isFuture() && $event->processed_at->timestamp <= $checked->timestamp
                && ($receipt['event_hash'] ?? null) === self::eventHash($event)
                && in_array($event->event_type, [...self::EVENTS, 'provider_status_checked', 'provider_status_ignored'], true);
        }

        return $event === null;
    }

    private static function eventHash(PaymentEvent $event): string
    {
        return ProviderReviewSnapshot::hash($event->only(['id', 'payment_id', 'provider', 'provider_object_type',
            'fib_subscription_id', 'fib_payment_id', 'local_reference', 'event_type', 'before_status', 'after_status', 'payload', 'meta']));
    }
}

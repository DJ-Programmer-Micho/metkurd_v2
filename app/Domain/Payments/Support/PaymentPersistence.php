<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Arr;

/** Versioned new-checkout contract. Never compacts an unversioned historical row. */
final class PaymentPersistence
{
    public static function apply(Payment $payment): void
    {
        if (! $payment->usesCompactPersistence()) {
            return;
        }
        $meta = (array) $payment->meta;
        $snapshot = (array) $payment->purchase_snapshot;
        if (! $payment->exists) {
            if (isset($meta['payment_method_code'])) {
                $snapshot['payment_method_code'] = $meta['payment_method_code'];
            }
            foreach (['original_amount_iqd', 'discount_amount_iqd', 'amount_iqd', 'gross_amount_iqd'] as $key) {
                unset($snapshot[$key]); // Authoritative immutable amount columns, exposed by snapshot().
            }
            foreach (['base_display', 'original_display'] as $key) {
                if (($snapshot[$key] ?? null) === ($snapshot['display'] ?? null)) {
                    unset($snapshot[$key]);
                }
            }
            unset($snapshot['intended_plan']); // Identity columns plus snapshot code/name.
            unset($snapshot['fee_quote']['base_amount_iqd'], $snapshot['fee_quote']['gross_amount_iqd']);
            $payment->purchase_snapshot = self::bounded($snapshot, 16384);
        } elseif ($payment->isDirty('purchase_snapshot')) {
            throw new \LogicException('Commercial checkout snapshot is immutable.');
        }
        foreach (['fee_quote', 'coupon', 'payment_method_code', 'payment_driver', 'provider_object_type'] as $key) {
            unset($meta[$key]);
        }
        unset($meta['application_review']['reason']);
        foreach (['latest_sync_failure', 'latest_renewal_sync_failure', 'latest_callback_failure'] as $key) {
            if (is_array($meta[$key] ?? null)) {
                // Retry identity/count/clock/pause live in the existing scalar operational keys.
                $meta[$key] = Arr::only($meta[$key], ['http_status', 'fib_error_code', 'fib_trace_id', 'profile', 'source', 'permanent', 'transient']);
            }
            unset($meta[$key.'_http_status'], $meta[$key.'_error_code'], $meta[$key.'_trace_id']);
        }
        $meta['persistence_version'] = 2;
        $payment->meta = self::bounded($meta, 16384);
        $payment->qr_code = null;
        $payment->callback_payload = null; // Receipt time and bounded wake-up metadata are sufficient.
        $payment->create_response = self::creation($payment->create_response);
        $payment->create_payload = self::request($payment->create_payload);
        $payment->status_response = self::status($payment->status_response);
        $payment->cancel_response = self::cancel($payment->cancel_response);
        $payment->provider_links = self::bounded($payment->provider_links, 8192);
        if ($payment->isDirty('mismatch_reason') && $payment->mismatch_reason !== null) {
            $payment->mismatch_reason = self::reason($payment->mismatch_reason);
        } elseif ($payment->isDirty('status_reason') && $payment->status_reason && ! $payment->mismatch_reason
            && $payment->status?->value === 'failed') {
            $payment->mismatch_reason = $payment->providerReference() === $payment->local_reference
                ? 'provider_create_failed' : 'provider_declined';
        }
        $payment->status_reason = null;
    }

    public static function reason(mixed $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return is_string($reason) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)
            ? $reason : 'manual_review_required';
    }

    public static function creation(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $result = Arr::only($raw, ['paymentId', 'subscriptionId', 'id', 'appLinkHost']);
        if (array_key_exists('appLink', $raw)) {
            unset($result['appLinkHost']);
            $url = is_string($raw['appLink']) ? parse_url($raw['appLink']) : false;
            if (($url['scheme'] ?? '') === 'https' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port'])) {
                $result['appLinkHost'] = $url['host'] ?? null;
            }
        }

        return self::bounded(self::retainCollectionHint($raw, $result), 1024);
    }

    public static function request(?array $raw): ?array
    {
        return $raw === null ? null : self::bounded(Arr::only($raw, ['description', 'monetaryValue', 'interval', 'trialPeriod']), 2048);
    }

    public static function status(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        // Keep all evidence aliases consumed by validators/mappers; discard banking PII,
        // QR, links, debug/errors and provider additions. Live validation happens first.
        $result = Arr::only($raw, ['id', 'subscriptionId', 'paymentId', 'status', 'paymentStatus', 'monetaryValue', 'amount',
            'localReference', 'merchantTransactionId', 'validUntil', 'activeUntil', 'paidAt', 'declinedAt', 'decliningReason',
            'lastPaymentAt', 'lastPaidAt', 'lastSuccessfulPaymentAt', 'latestPaidAt', 'isPaid', 'paid', 'paymentCompleted', 'isPaymentCompleted']);
        foreach (['payment', 'latestPayment', 'subscription'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                $result[$key] = Arr::only($raw[$key], ['status', 'paymentStatus', 'lastPaymentAt', 'lastPaidAt', 'isPaid', 'paid']);
            }
        }
        foreach (['monetaryValue', 'amount'] as $key) {
            if (is_array($result[$key] ?? null)) {
                $result[$key] = Arr::only($result[$key], ['amount', 'currency']);
            }
        }

        return self::bounded(self::retainCollectionHint($raw, $result), 4096);
    }

    public static function cancel(?array $raw): ?array
    {
        return $raw === null ? null : self::bounded(self::retainCollectionHint($raw, Arr::only($raw, ['accepted', 'result', 'source', 'provider_object_type', 'provider_status', 'trace_id', 'error_codes'])), 2048);
    }

    public static function callback(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $result = [];
        foreach (['id', 'paymentId', 'subscriptionId', 'status', 'paymentStatus'] as $key) {
            if (is_string($raw[$key] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $raw[$key])) {
                $result[$key] = $raw[$key];
            }
        }

        return self::retainCollectionHint($raw, $result);
    }

    /** Preserve conservative invalidation blockers without treating an unknown field as payment truth. */
    private static function retainCollectionHint(array $raw, array $result): array
    {
        $guard = app(\App\Services\Admin\AdminProviderEvidence::class);
        if ($guard->containsCollectionEvidence($raw) && ! $guard->containsCollectionEvidence($result)) {
            $result['unmapped_collection_evidence'] = true;
        }

        return $result;
    }

    public static function event(array $attributes): array
    {
        $type = $attributes['event_type'] ?? '';
        $attributes['payload'] = match (true) {
            in_array($type, ['provider_payment_created', 'provider_subscription_created'], true) => self::creation($attributes['payload'] ?? null),
            in_array($type, [...ProviderObservation::EVENTS, 'provider_status_checked', 'provider_status_ignored', 'payment_status_checked'], true) => self::status($attributes['payload'] ?? null),
            str_starts_with($type, 'callback_') => self::callback($attributes['payload'] ?? null),
            default => self::bounded($attributes['payload'] ?? null, 16384),
        };
        if (isset($attributes['meta']['create_payload'])) {
            $attributes['meta']['create_payload'] = self::request($attributes['meta']['create_payload']);
        }
        if (in_array($type, ['provider_payment_create_failed', 'provider_subscription_create_failed'], true)) {
            unset($attributes['meta']['message']);
            $attributes['meta']['reason_code'] = 'provider_create_failed';
        }
        $attributes['meta'] = self::bounded($attributes['meta'] ?? null, 16384);

        return $attributes;
    }

    public static function bounded(?array $value, int $bytes): ?array
    {
        if ($value === null) {
            return null;
        }
        $walk = function (array $items, int $depth) use (&$walk): array {
            if ($depth > 8 || count($items) > 64) {
                throw new \LengthException('Payment evidence exceeds structural bounds.');
            }
            foreach ($items as $key => $item) {
                if (in_array(strtolower((string) $key), ['qr_code', 'qrcode', 'callback_payload', 'purchase_snapshot', 'create_response', 'status_response', 'cancel_response', 'raw', 'body', 'access_token', 'client_secret', 'password'], true)
                    || (is_string($item) && str_starts_with($item, 'data:'))) {
                    unset($items[$key]);
                } elseif (is_array($item)) {
                    $items[$key] = $walk($item, $depth + 1);
                } elseif (is_string($item) && strlen($item) > 2048) {
                    throw new \LengthException('Payment evidence string exceeds bounds.');
                }
            }

            return $items;
        };
        $value = $walk($value, 0);
        if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > $bytes) {
            throw new \LengthException('Payment evidence exceeds storage bounds.');
        }

        return $value;
    }
}

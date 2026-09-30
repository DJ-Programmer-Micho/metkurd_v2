<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use Illuminate\Support\Arr;

class PaymentEventRecorder
{
    /** Exact legacy aliases, not truncation: checkout and renewal remain distinct. */
    public static function canonicalSource(string $source): string
    {
        return match ($source) {
            'scheduled_subscription_checkout_reconciliation' => 'scheduled_sub_checkout',
            'scheduled_subscription_renewal_reconciliation' => 'scheduled_sub_renewal',
            default => $source,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(?Payment $payment, array $attributes): PaymentEvent
    {
        $source = self::canonicalSource((string) Arr::get($attributes, 'source', 'system'));
        if (strlen($source) > 40) {
            throw new \InvalidArgumentException('Payment event source exceeds its schema contract.');
        }
        $provider = $payment?->provider ?? PaymentProvider::FIB;
        $eventKey = Arr::get($attributes, 'event_key');
        $payload = [
            'payment_id' => $payment?->id,
            'provider' => $provider->value,
            'event_type' => (string) Arr::get($attributes, 'event_type'),
            'source' => $source,
            'provider_object_type' => Arr::get($attributes, 'provider_object_type', $payment?->provider_object_type?->value ?? 'payment'),
            'local_reference' => Arr::get($attributes, 'local_reference', $payment?->local_reference),
            'fib_payment_id' => Arr::get($attributes, 'fib_payment_id', $payment?->fib_payment_id),
            'fib_subscription_id' => Arr::get($attributes, 'fib_subscription_id', $payment?->fib_subscription_id),
            'before_status' => Arr::get($attributes, 'before_status'),
            'after_status' => Arr::get($attributes, 'after_status'),
            'response_code' => Arr::get($attributes, 'response_code'),
            'payload' => Arr::get($attributes, 'payload'),
            'meta' => Arr::get($attributes, 'meta'),
            'processed_at' => Arr::get($attributes, 'processed_at', now()),
        ];

        if (is_string($eventKey) && trim($eventKey) !== '') {
            return PaymentEvent::query()->firstOrCreate(
                ['event_key' => $eventKey],
                array_merge($payload, ['event_key' => $eventKey]),
            );
        }

        return PaymentEvent::create($payload);
    }
}

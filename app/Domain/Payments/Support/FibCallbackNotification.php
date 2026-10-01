<?php

namespace App\Domain\Payments\Support;

use Illuminate\Http\Request;

final class FibCallbackNotification
{
    /** Owned callbacks are bounded wake-up state, not lifecycle events or payment truth. */
    public static function received(\App\Domain\Payments\Models\Payment $payment, array $payload): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $payload) {
            $locked = $payment->newQuery()->lockForUpdate()->findOrFail($payment->id);
            $meta = (array) $locked->meta;
            $previous = (array) ($meta['provider_callback'] ?? []);
            $meta['provider_callback_version'] = ProviderObservation::callbackVersion($locked) + 1;
            $meta['provider_callback'] = ['notification' => PaymentPersistence::callback($payload),
                'unmapped_collection_evidence' => (bool) ($previous['unmapped_collection_evidence'] ?? false)
                    || app(\App\Services\Admin\AdminProviderEvidence::class)->containsCollectionEvidence($payload)];
            $locked->forceFill(['meta' => $meta, 'last_callback_received_at' => now()])->save();
        });
    }

    // The public FIB contract establishes no cryptographic signature. These are
    // bounded, untrusted wake-ups; only a matched authenticated GET proves payment.
    public static function identifier(Request $request, string $alias): ?string
    {
        $id = $request->input('id') ?? $request->input($alias);
        $other = $request->input($alias);
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id)
            || ($other !== null && $other !== $id) || strlen($request->getContent()) > 16384) {
            return null;
        }
        foreach (['status', 'paymentStatus'] as $key) {
            $value = $request->input($key);
            if ($value !== null && (! is_string($value) || strlen($value) > 64)) {
                return null;
            }
        }

        return $id;
    }

    public static function payload(Request $request): array
    {
        $payload = [];
        foreach (['id', 'paymentId', 'subscriptionId', 'status', 'paymentStatus'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && strlen($value) <= 128) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}

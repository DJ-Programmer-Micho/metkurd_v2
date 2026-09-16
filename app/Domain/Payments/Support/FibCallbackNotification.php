<?php

namespace App\Domain\Payments\Support;

use Illuminate\Http\Request;

final class FibCallbackNotification
{
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

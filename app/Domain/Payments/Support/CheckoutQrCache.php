<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/** Disposable checkout presentation only; never financial evidence or a database cache. */
final class CheckoutQrCache
{
    public static function remember(Payment $payment, ?string $qr, ?CarbonInterface $expires): void
    {
        if (! $payment->usesCompactPersistence() || ! $qr || strlen($qr) > 262144
            || ! preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=\r\n]+$~', $qr)) {
            return;
        }
        $expires ??= app(PaymentCheckoutState::class)->deadline($payment);
        // Follow the checkout deadline, never the subscription term or a guessed lifetime.
        if (! $expires) {
            return;
        }
        $ttl = $expires->getTimestamp() - now()->getTimestamp();
        if ($ttl <= 0) {
            return;
        }
        try {
            Cache::store(self::store())->put(self::key($payment), $qr, $ttl);
        } catch (\Throwable) {
            // A cache outage must not lose a remotely created financial object.
        }
    }

    public static function read(Payment $payment): ?string
    {
        if (! $payment->usesCompactPersistence()) {
            return $payment->qr_code;
        }
        try {
            $qr = Cache::store(self::store())->get(self::key($payment));

            return is_string($qr) && strlen($qr) <= 262144 ? $qr : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function store(): string
    {
        $store = (string) config('cache.default');
        if (in_array(config('cache.stores.'.$store.'.driver'), ['redis', 'memcached', 'file', 'array'], true)) {
            return $store;
        }
        if (config('cache.stores.file.driver') !== 'file') {
            throw new \LogicException('No non-database checkout QR cache is configured.');
        }

        return 'file';
    }

    private static function key(Payment $payment): string
    {
        return 'payment-checkout-qr:'.$payment->customer_id.':'.$payment->uuid;
    }
}

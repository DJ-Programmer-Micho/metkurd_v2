<?php

namespace App\Services\Payments;

use App\Jobs\Payments\SyncPendingPaymentIntentJob;
use App\Models\PaymentIntent;

class PaymentIntentStatusSyncService
{
    public function __construct(
        protected PaymentIntentService $intents,
    ) {
    }

    public function sync(PaymentIntent|int $intent, array $options = []): PaymentIntent
    {
        return $this->intents->syncCheckout($intent, $options);
    }

    public function scheduleFallback(PaymentIntent $intent): void
    {
        if ($intent->isTerminal()) {
            return;
        }

        SyncPendingPaymentIntentJob::dispatch((int) $intent->id)
            ->delay(now()->addSeconds((int) config('fib.status_sync.delay_seconds', 15)));
    }
}

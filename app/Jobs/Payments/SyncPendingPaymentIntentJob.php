<?php

namespace App\Jobs\Payments;

use App\Models\PaymentIntent;
use App\Services\Payments\PaymentIntentStatusSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncPendingPaymentIntentJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public function __construct(
        public readonly int $paymentIntentId,
    ) {
        $this->tries = max(1, (int) config('fib.status_sync.max_attempts', 5));
    }

    public function handle(PaymentIntentStatusSyncService $sync): void
    {
        $intent = PaymentIntent::query()->find($this->paymentIntentId);

        if (! $intent instanceof PaymentIntent || $intent->isTerminal()) {
            return;
        }

        $updatedIntent = $sync->sync($intent, [
            'source' => 'fallback_job',
        ]);

        if (
            ! $updatedIntent->isTerminal()
            && ($updatedIntent->expires_at === null || now()->lt($updatedIntent->expires_at))
            && $this->attempts() < $this->tries
        ) {
            $this->release((int) config('fib.status_sync.delay_seconds', 15));
        }
    }
}

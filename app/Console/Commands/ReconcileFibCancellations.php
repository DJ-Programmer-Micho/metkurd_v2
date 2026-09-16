<?php

namespace App\Console\Commands;

use App\Domain\Payments\Models\Payment;
use App\Services\Billing\ProviderSubscriptionCancellation;
use Illuminate\Console\Command;

class ReconcileFibCancellations extends Command
{
    protected $signature = 'payments:reconcile-fib-cancellations {--customer-id=} {--limit=100} {--dry-run}';

    protected $description = 'Retry durable FIB cancellation intents, including superseded and expired subscriptions.';

    public function handle(ProviderSubscriptionCancellation $cancellations): int
    {
        $query = Payment::currentBillingPeriod()->where('provider', 'fib')->where('provider_object_type', 'subscription')
            ->where('meta->provider_cancellation->provider_cancel_pending', true)
            ->where(fn ($q) => $q->whereNull('meta->provider_cancellation->retry_after')
                ->orWhere('meta->provider_cancellation->retry_after', '<=', now()->toIso8601String()));
        if ($this->option('customer-id')) {
            $query->where('customer_id', (int) $this->option('customer-id'));
        }
        $failed = 0;
        foreach ($query->orderBy('meta->provider_cancellation->last_attempt_at')->orderBy('id')
            ->limit(min(1000, max(1, (int) $this->option('limit'))))->get() as $payment) {
            if ($this->option('dry-run')) {
                $this->line('Pending provider cancellation: Payment '.$payment->id);

                continue;
            }
            try {
                $cancellations->process($payment);
            } catch (\Throwable) {
                $failed++;
                $this->error('Payment '.$payment->id.' remains pending; retry is required.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ReconcileFibSubscriptions extends Command
{
    protected $signature = 'payments:reconcile-fib-subscriptions
        {--customer-id= : Reconcile only subscriptions for a single customer id}
        {--chunk=100 : Number of subscriptions to reconcile per chunk}
        {--limit=0 : Maximum number of subscriptions to process (0 means no limit)}
        {--stale-minutes=5 : Only reconcile records that were not checked within this window}';

    protected $description = 'Reconcile FIB recurring subscription state and lifecycle changes.';

    public function handle(SyncFibCheckoutStatus $sync, PaymentEventRecorder $events): int
    {
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $staleMinutes = max(0, (int) $this->option('stale-minutes'));
        $customerId = (int) $this->option('customer-id');

        $query = Payment::query()
            ->where('provider', PaymentProvider::FIB)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->whereNotNull('fib_subscription_id')
            ->where(function ($builder) {
                $builder
                    ->whereIn('status', ['pending', 'awaiting_customer_action', 'paid'])
                    ->orWhere(function ($recentTerminal) {
                        $recentTerminal
                            ->whereIn('status', ['canceled', 'failed', 'expired'])
                            ->whereNotNull('active_until')
                            ->where('active_until', '>=', now()->subDay());
                    });
            });

        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        if ($staleMinutes > 0) {
            $staleThreshold = now()->subMinutes($staleMinutes);
            $query->where(function ($builder) use ($staleThreshold) {
                $builder
                    ->whereNull('last_status_checked_at')
                    ->orWhere('last_status_checked_at', '<=', $staleThreshold);
            });
        }

        $candidateCount = (clone $query)->count();

        if ($candidateCount === 0) {
            $this->info('No FIB subscriptions needed reconciliation.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $failed = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($sync, $events, $limit, &$processed, &$updated, &$failed) {
                foreach ($payments as $payment) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    ++$processed;

                    $before = $this->syncFingerprint($payment);

                    try {
                        $refreshed = $sync->handle($payment, 'scheduled_reconciliation')->fresh() ?? $payment->fresh() ?? $payment;
                    } catch (\Throwable $exception) {
                        ++$failed;

                        // Log::warning('FIB subscription reconciliation failed.', [
                        //     'payment_id' => $payment->id,
                        //     'customer_id' => $payment->customer_id,
                        //     'provider_ref' => $payment->providerReference(),
                        //     'message' => $exception->getMessage(),
                        // ]);

                        $events->record($payment, [
                            'event_type' => 'provider_status_sync_failed',
                            'source' => 'scheduled_reconciliation',
                            'event_key' => 'subscription-reconciliation-failed:' . $payment->id . ':' . now()->format('YmdHi'),
                            'before_status' => $payment->status->value,
                            'after_status' => $payment->status->value,
                            'meta' => [
                                'message' => $exception->getMessage(),
                            ],
                        ]);

                        continue;
                    }

                    if ($this->syncFingerprint($refreshed) !== $before) {
                        ++$updated;
                    }
                }

                return true;
            });

        $this->info('FIB subscription reconciliation completed.');
        $this->line('Candidates: ' . number_format($candidateCount));
        $this->line('Processed: ' . number_format($processed));
        $this->line('Updated: ' . number_format($updated));
        $this->line('Failed: ' . number_format($failed));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    protected function syncFingerprint(Payment $payment): array
    {
        return [
            'status' => $payment->status->value,
            'provider_status' => $payment->providerStatusLabel(),
            'active_until' => $this->toIso($payment->active_until),
            'last_payment_at' => $this->toIso($payment->last_payment_at),
            'last_status_checked_at' => $this->toIso($payment->last_status_checked_at),
        ];
    }

    protected function toIso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}

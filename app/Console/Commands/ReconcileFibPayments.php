<?php

namespace App\Console\Commands;

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Services\Payments\PaymentSyncFailureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileFibPayments extends Command
{
    protected $signature = 'payments:reconcile-fib-payments
        {--customer-id= : Reconcile only payments for a single customer id}
        {--chunk=100 : Number of payments to reconcile per chunk}
        {--limit=0 : Maximum number of payments to process (0 means no limit)}
        {--stale-minutes=5 : Only reconcile records that were not checked within this window}
        {--dry-run : Show which records would be processed without mutating data}';

    protected $description = 'Reconcile FIB payment and subscription records when callbacks are delayed or missed.';

    public function handle(
        SyncFibCheckoutStatus $sync,
        PaymentEventRecorder $events,
        PaymentSyncFailureService $failures,
    ): int {
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $staleMinutes = max(0, (int) $this->option('stale-minutes'));
        $customerId = (int) $this->option('customer-id');
        $dryRun = (bool) $this->option('dry-run');

        $query = Payment::query()
            ->where('provider', PaymentProvider::FIB)
            ->where(function ($builder) {
                $builder->whereNotNull('fib_payment_id')->orWhereNotNull('fib_subscription_id');
            })
            ->where(function ($builder) {
                $builder
                    ->whereNull('internal_status')
                    ->orWhere('internal_status', '!=', PaymentInternalStatus::REQUIRES_REVIEW->value);
            })
            ->where(function ($builder) {
                $builder
                    ->whereIn('status', ['pending', 'awaiting_customer_action'])
                    ->orWhere(function ($paidLike) {
                        $paidLike
                            ->where('status', 'paid')
                            ->whereNull('fulfilled_at')
                            ->where(function ($statuses) {
                                $statuses
                                    ->whereNull('internal_status')
                                    ->orWhereIn('internal_status', [
                                        PaymentInternalStatus::PAID_PENDING_APPLICATION->value,
                                        PaymentInternalStatus::REQUIRES_REVIEW->value,
                                    ]);
                            });
                    });
            });

        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        if ($staleMinutes > 0) {
            $threshold = now()->subMinutes($staleMinutes);
            $query->where(function ($builder) use ($threshold) {
                $builder
                    ->whereNull('last_status_checked_at')
                    ->orWhere('last_status_checked_at', '<=', $threshold);
            });
        }

        $candidateCount = (clone $query)->count();

        if ($candidateCount === 0) {
            $this->info('No FIB payments needed reconciliation.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $failed = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($sync, $events, $failures, $limit, $dryRun, &$processed, &$updated, &$failed) {
                foreach ($payments as $payment) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $processed++;
                    $before = $this->fingerprint($payment);

                    if ($dryRun) {
                        if ($processed <= 20) {
                            $this->line(sprintf(
                                '[dry-run] payment_id=%d customer_id=%d status=%s internal_status=%s provider_ref=%s',
                                (int) $payment->id,
                                (int) $payment->customer_id,
                                (string) $payment->status->value,
                                (string) ($payment->internal_status?->value ?? 'n/a'),
                                $payment->providerReference(),
                            ));
                        }

                        continue;
                    }

                    if ($this->shouldExpireLocally($payment)) {
                        $this->expireLocally($payment, $events);

                        if ($this->fingerprint($payment->fresh() ?? $payment) !== $before) {
                            $updated++;
                        }

                        continue;
                    }

                    try {
                        $refreshed = $sync->handle($payment, 'scheduled_payment_reconciliation')->fresh() ?? $payment->fresh() ?? $payment;
                    } catch (\Throwable $exception) {
                        $failed++;

                        $failures->capture($payment, $exception, 'scheduled_payment_reconciliation');

                        if ($this->fingerprint($payment->fresh() ?? $payment) !== $before) {
                            $updated++;
                        }

                        continue;
                    }

                    if ($this->fingerprint($refreshed) !== $before) {
                        $updated++;
                    }
                }

                return true;
            });

        $this->info($dryRun
            ? 'FIB payment reconciliation dry-run completed.'
            : 'FIB payment reconciliation completed.');
        $this->line('Candidates: '.number_format($candidateCount));
        $this->line('Processed: '.number_format($processed));
        $this->line('Updated: '.number_format($dryRun ? 0 : $updated));
        $this->line('Failed: '.number_format($dryRun ? 0 : $failed));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    protected function fingerprint(Payment $payment): array
    {
        return [
            'status' => $payment->status->value,
            'internal_status' => $payment->internal_status?->value,
            'provider_status' => $payment->providerStatusLabel(),
            'fulfilled_at' => $payment->fulfilled_at?->toIso8601String(),
            'review_required_at' => $payment->review_required_at?->toIso8601String(),
            'last_status_checked_at' => $payment->last_status_checked_at?->toIso8601String(),
        ];
    }

    protected function shouldExpireLocally(Payment $payment): bool
    {
        if (filled($payment->fib_payment_id) || filled($payment->fib_subscription_id)) {
            return false;
        }

        if (! in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::AWAITING_CUSTOMER_ACTION], true)) {
            return false;
        }

        if ($payment->paid_at !== null || $payment->fulfilled_at !== null || $payment->last_payment_at !== null) {
            return false;
        }

        return $payment->valid_until !== null && $payment->valid_until->isPast();
    }

    protected function expireLocally(Payment $payment, PaymentEventRecorder $events): void
    {
        DB::transaction(function () use ($payment, $events) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $this->shouldExpireLocally($locked)) {
                return;
            }

            $beforeStatus = $locked->status->value;

            $locked->forceFill([
                'status' => PaymentStatus::EXPIRED,
                'internal_status' => PaymentInternalStatus::EXPIRED,
                'expired_at' => $locked->expired_at ?? now(),
                'failed_at' => $locked->failed_at ?? now(),
                'status_reason' => 'Local FIB checkout expired before a verified paid callback or successful provider refresh.',
                'last_status_checked_at' => now(),
            ])->save();

            $events->record($locked, [
                'event_type' => 'provider_status_expired_locally',
                'source' => 'scheduled_payment_reconciliation_local_expiry',
                'event_key' => 'payment-local-expiry:'.$locked->id,
                'before_status' => $beforeStatus,
                'after_status' => PaymentStatus::EXPIRED->value,
                'meta' => [
                    'valid_until' => $locked->valid_until?->toIso8601String(),
                ],
            ]);
        }, 3);
    }
}

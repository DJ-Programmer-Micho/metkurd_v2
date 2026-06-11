<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithFibCheckoutReconciliation;
use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Services\Payments\PaymentSyncFailureService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReconcileFibSubscriptions extends Command
{
    use InteractsWithFibCheckoutReconciliation;

    protected $signature = 'payments:reconcile-fib-subscriptions
        {--customer-id= : Reconcile only subscriptions for a single customer id}
        {--chunk=100 : Number of subscriptions to reconcile per chunk}
        {--limit=0 : Maximum number of subscriptions to process (0 means no limit)}
        {--stale-minutes=5 : Only reconcile records that were not checked within this window}
        {--dry-run : Show which records would be processed without mutating data}';

    protected $description = 'Reconcile unresolved FIB recurring subscription checkout records when callbacks are delayed or missed.';

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

        $baseQuery = Payment::query()
            ->where('provider', PaymentProvider::FIB)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->whereNotNull('fib_subscription_id');

        if ($customerId > 0) {
            $baseQuery->where('customer_id', $customerId);
        }

        if ($staleMinutes > 0) {
            $staleThreshold = now()->subMinutes($staleMinutes);
            $baseQuery->where(function ($builder) use ($staleThreshold) {
                $builder
                    ->whereNull('last_status_checked_at')
                    ->orWhere('last_status_checked_at', '<=', $staleThreshold);
            });
        }

        $summary = $this->candidateSummary(clone $baseQuery);
        $query = $this->unresolvedCheckoutQuery(clone $baseQuery);
        $candidateCount = (int) ($summary['processed_unresolved'] ?? 0);

        if ($candidateCount === 0) {
            $this->info('No FIB subscriptions needed reconciliation.');
            $this->renderSummary($summary, $dryRun);

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

                    $before = $this->syncFingerprint($payment);

                    if ($dryRun) {
                        if ($processed <= 20) {
                            $this->line(sprintf(
                                '[dry-run] payment_id=%d customer_id=%d status=%s internal_status=%s provider_status=%s active_until=%s',
                                (int) $payment->id,
                                (int) $payment->customer_id,
                                (string) $payment->status->value,
                                (string) ($payment->internal_status?->value ?? 'n/a'),
                                (string) ($payment->providerStatusLabel() ?? 'n/a'),
                                (string) ($this->toIso($payment->active_until) ?? 'n/a')
                            ));
                        }

                        continue;
                    }

                    if ($this->shouldExpireLocally($payment)) {
                        $this->expireLocally($payment, $events);

                        if ($this->syncFingerprint($payment->fresh() ?? $payment) !== $before) {
                            $updated++;
                        }

                        continue;
                    }

                    try {
                        $refreshed = $sync->handle($payment, 'scheduled_subscription_checkout_reconciliation')->fresh() ?? $payment->fresh() ?? $payment;
                    } catch (\Throwable $exception) {
                        $failed++;

                        $failures->capture($payment, $exception, 'scheduled_subscription_checkout_reconciliation');

                        if ($this->syncFingerprint($payment->fresh() ?? $payment) !== $before) {
                            $updated++;
                        }

                        continue;
                    }

                    if ($this->syncFingerprint($refreshed) !== $before) {
                        $updated++;
                    }
                }

                return true;
            });

        $this->info($dryRun
            ? 'FIB subscription reconciliation dry-run completed.'
            : 'FIB subscription reconciliation completed.');
        $summary['processed_unresolved'] = $processed;
        $summary['updated'] = $dryRun ? 0 : $updated;
        $summary['failed'] = $dryRun ? 0 : $failed;
        $this->renderSummary($summary, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    protected function syncFingerprint(Payment $payment): array
    {
        return [
            'status' => $payment->status->value,
            'internal_status' => $payment->internal_status?->value,
            'provider_status' => $payment->providerStatusLabel(),
            'active_until' => $this->toIso($payment->active_until),
            'last_payment_at' => $this->toIso($payment->last_payment_at),
            'fulfilled_at' => $this->toIso($payment->fulfilled_at),
            'review_required_at' => $this->toIso($payment->review_required_at),
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
                'status_reason' => 'Local FIB subscription checkout expired before a verified paid callback or successful provider refresh.',
                'last_status_checked_at' => now(),
            ])->save();

            $events->record($locked, [
                'event_type' => 'provider_status_expired_locally',
                'source' => 'scheduled_subscription_checkout_reconciliation_local_expiry',
                'event_key' => 'subscription-local-expiry:'.$locked->id,
                'before_status' => $beforeStatus,
                'after_status' => PaymentStatus::EXPIRED->value,
                'meta' => [
                    'valid_until' => $locked->valid_until?->toIso8601String(),
                ],
            ]);
        }, 3);
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Services\Payments\PaymentSyncFailureService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileFibSubscriptionRenewals extends Command
{
    protected $signature = 'payments:reconcile-fib-subscription-renewals
        {--customer-id= : Reconcile only renewals for a single customer id}
        {--chunk=100 : Number of recurring payments to reconcile per chunk}
        {--limit=0 : Maximum recurring payments to process (0 means no limit)}
        {--stale-minutes=5 : Only reconcile records that were not checked within this window}
        {--dry-run : Show which records would be processed without mutating data}';

    protected $description = 'Reconcile active FIB recurring subscription renewals separately from checkout completion polling.';

    public function handle(
        SyncFibCheckoutStatus $sync,
        PaymentSyncFailureService $failures,
    ): int {
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $staleMinutes = max(0, (int) $this->option('stale-minutes'));
        $customerId = (int) $this->option('customer-id');
        $dryRun = (bool) $this->option('dry-run');

        $query = Payment::query()->currentBillingPeriod()
            ->where('provider', PaymentProvider::FIB->value)
            ->where('payment_mode', PaymentMode::RECURRING->value)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION->value)
            ->where('status', PaymentStatus::PAID->value)
            ->whereNotNull('fib_subscription_id')
            ->whereNull('meta->provider_cancellation->requested_at')
            ->whereNull('meta->supersession->superseded_at')
            ->where(fn ($q) => $q->whereNull('provider_subscription_status')->orWhereNotIn('provider_subscription_status', ['CANCELLED', 'CANCELED']))
            ->where(function ($builder) {
                $builder
                    ->whereNotNull('fulfilled_at')
                    ->orWhere('internal_status', PaymentInternalStatus::APPLIED->value);
            })
            ->where(function ($builder) {
                $builder
                    ->where(function ($service) {
                        $service
                            ->where('purchase_type', PurchaseType::PLAN_SUBSCRIPTION->value)
                            ->whereHas('serviceSubscriptions', function ($subscriptions) {
                                $subscriptions->where('status', 'active');
                            });
                    })
                    ->orWhere(function ($storage) {
                        $storage
                            ->where('purchase_type', PurchaseType::STORAGE_SUBSCRIPTION->value)
                            ->whereHas('storageSubscriptions', function ($subscriptions) {
                                $subscriptions->where('status', 'active');
                            });
                    })
                    ->orWhere(function ($activeWindow) {
                        $activeWindow
                            ->whereNotNull('active_until')
                            ->where('active_until', '>=', now());
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
            $this->info('No active FIB subscription renewals needed reconciliation.');
            $this->renderSummary([
                'candidates_scanned' => 0,
                'processed' => 0,
                'updated' => 0,
                'failed' => 0,
            ], $dryRun);

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $failed = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($sync, $failures, $limit, $dryRun, &$processed, &$updated, &$failed) {
                foreach ($payments as $payment) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $processed++;
                    $before = $this->fingerprint($payment);

                    if ($dryRun) {
                        if ($processed <= 20) {
                            $this->line(sprintf(
                                '[dry-run] payment_id=%d customer_id=%d status=%s provider_status=%s active_until=%s last_payment_at=%s',
                                (int) $payment->id,
                                (int) $payment->customer_id,
                                (string) $payment->status->value,
                                (string) ($payment->providerStatusLabel() ?? 'n/a'),
                                (string) ($this->toIso($payment->active_until) ?? 'n/a'),
                                (string) ($this->toIso($payment->last_payment_at) ?? 'n/a'),
                            ));
                        }

                        continue;
                    }

                    try {
                        $refreshed = $sync
                            ->handle($payment, 'scheduled_sub_renewal', null, false, true)
                            ->fresh() ?? $payment->fresh() ?? $payment;
                    } catch (\Throwable $exception) {
                        $failed++;

                        $failures->captureRenewalFailure($payment, $exception, 'scheduled_sub_renewal');

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
            ? 'FIB subscription renewal reconciliation dry-run completed.'
            : 'FIB subscription renewal reconciliation completed.');
        $this->renderSummary([
            'candidates_scanned' => $candidateCount,
            'processed' => $processed,
            'updated' => $dryRun ? 0 : $updated,
            'failed' => $dryRun ? 0 : $failed,
        ], $dryRun);

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
            'active_until' => $this->toIso($payment->active_until),
            'last_payment_at' => $this->toIso($payment->last_payment_at),
            'last_status_checked_at' => $this->toIso($payment->last_status_checked_at),
            'renewal_failure_signature' => (string) data_get($payment->meta, 'latest_renewal_sync_failure_signature'),
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

    /**
     * @param  array{candidates_scanned:int,processed:int,updated:int,failed:int}  $summary
     */
    protected function renderSummary(array $summary, bool $dryRun): void
    {
        $this->line('Candidates scanned: '.number_format((int) ($summary['candidates_scanned'] ?? 0)));
        $this->line('Processed renewals: '.number_format((int) ($summary['processed'] ?? 0)));
        $this->line('Updated: '.number_format((int) ($summary['updated'] ?? 0)));
        $this->line('Failed: '.number_format((int) ($summary['failed'] ?? 0)));

        if ($dryRun) {
            $this->line('Mode: dry-run');
        }
    }
}

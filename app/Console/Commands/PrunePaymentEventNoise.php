<?php

namespace App\Console\Commands;

use App\Domain\Payments\Models\PaymentEvent;
use Illuminate\Console\Command;

class PrunePaymentEventNoise extends Command
{
    protected $signature = 'payments:prune-event-noise
        {--event=provider_status_sync_failed : Event type to prune}
        {--keep-latest=50 : Number of newest rows to keep per payment/source/reference group}
        {--payment-id= : Limit pruning to a single payment id}
        {--dry-run : Show what would be deleted without mutating data}
        {--force : Delete without interactive confirmation}';

    protected $description = 'Prune repeated noisy payment event rows while preserving the latest audit samples per payment/source/reference group.';

    protected const PROTECTED_EVENT_TYPES = [
        'local_payment_created',
        'provider_payment_created',
        'provider_subscription_created',
        'provider_status_changed',
        'provider_collection_verified',
        'provider_evidence_changed',
        'callback_received',
        'callback_processed',
        'payment_fulfilled',
        'service_subscription_started',
        'storage_subscription_started',
        'service_subscription_renewed',
        'storage_subscription_renewed',
        'manual_provider_recovery_created',
        'payment_requires_review',
        'admin_payment_review_resolved',
        'admin_payment_refund_confirmed',
        'admin_payment_applied_manually',
        'admin_payment_marked_invalid',
        'admin_payment_marked_non_revenue',
        'admin_payment_reference_attached',
        'manual_revenue_reclassified',
    ];

    public function handle(): int
    {
        $eventType = trim((string) $this->option('event')) ?: 'provider_status_sync_failed';
        $keepLatest = max(1, (int) $this->option('keep-latest'));
        $paymentId = max(0, (int) $this->option('payment-id'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (in_array($eventType, self::PROTECTED_EVENT_TYPES, true)) {
            $this->error(sprintf(
                'Refusing to prune protected audit event type [%s]. Choose a noisy operational event type such as provider_status_sync_failed instead.',
                $eventType
            ));

            return self::FAILURE;
        }

        $query = PaymentEvent::query()
            ->where('event_type', $eventType);

        if ($paymentId > 0) {
            $query->where('payment_id', $paymentId);
        }

        $matchingRows = (clone $query)->count();

        if ($matchingRows === 0) {
            $this->info('No matching payment events found.');

            return self::SUCCESS;
        }

        $rowsToDelete = [];
        $groupCounts = [];

        $query
            ->orderBy('provider')
            ->orderBy('source')
            ->orderBy('provider_object_type')
            ->orderBy('payment_id')
            ->orderBy('fib_payment_id')
            ->orderBy('fib_subscription_id')
            ->orderBy('local_reference')
            ->orderByDesc('id')
            ->cursor()
            ->each(function (PaymentEvent $event) use (&$rowsToDelete, &$groupCounts, $keepLatest): void {
                $groupKey = $this->groupKey($event);
                $groupCounts[$groupKey] = ($groupCounts[$groupKey] ?? 0) + 1;

                if ($groupCounts[$groupKey] > $keepLatest) {
                    $rowsToDelete[] = (int) $event->id;
                }
            });

        $summaryRows = [
            ['event_type', $eventType],
            ['keep_latest', $keepLatest],
            ['payment_id_scope', $paymentId > 0 ? $paymentId : 'all'],
            ['matching_rows', $matchingRows],
            ['groups', count($groupCounts)],
            ['rows_to_delete', count($rowsToDelete)],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ];

        $this->newLine();
        $this->info('PAYMENT EVENT NOISE PRUNE');
        $this->table(['Key', 'Value'], $summaryRows);
        $this->warn('Recommendation: take a database backup or snapshot before deleting historical payment event rows.');

        if ($rowsToDelete === []) {
            $this->info('Nothing to prune after applying the keep-latest window.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry-run only; no rows were deleted.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm(sprintf('Delete %d payment_events rows now?', count($rowsToDelete)))) {
            $this->warn('Prune cancelled.');

            return self::FAILURE;
        }

        $deleted = 0;

        foreach (array_chunk($rowsToDelete, 1000) as $chunk) {
            $deleted += PaymentEvent::query()->whereIn('id', $chunk)->delete();
        }

        $this->info(sprintf('Deleted %d payment_events rows.', $deleted));

        return self::SUCCESS;
    }

    protected function groupKey(PaymentEvent $event): string
    {
        return implode('|', [
            (string) $event->provider,
            (string) $event->event_type,
            (string) $event->source,
            (string) $event->provider_object_type,
            (string) ($event->payment_id ?? 0),
            (string) ($event->fib_payment_id ?? ''),
            (string) ($event->fib_subscription_id ?? ''),
            (string) ($event->local_reference ?? ''),
        ]);
    }
}

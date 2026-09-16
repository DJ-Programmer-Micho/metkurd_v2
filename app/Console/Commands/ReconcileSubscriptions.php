<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Services\Billing\CustomerBillingStateService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile
        {--customer= : Reconcile one customer id only}
        {--chunk=100 : Number of subscriptions to process per chunk}
        {--limit=0 : Maximum subscriptions to process (0 means no limit)}
        {--stale-minutes=5 : Provider status polling freshness window for FIB sync}
        {--grace-minutes=0 : Extra grace after period end before local downgrade}
        {--skip-provider-sync : Skip provider status sync and run only local overdue reconciliation}
        {--dry-run : Show what would change without mutating data}';

    protected $description = 'Reconcile recurring subscriptions and downgrade safely after failed/expired renewals.';

    public function handle(PaymentEventRecorder $events, CustomerBillingStateService $billingState): int
    {
        $customerId = (int) $this->option('customer');
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $staleMinutes = max(0, (int) $this->option('stale-minutes'));
        $graceMinutes = max(0, (int) $this->option('grace-minutes'));
        $skipProviderSync = (bool) $this->option('skip-provider-sync');
        $dryRun = (bool) $this->option('dry-run');

        if (! $skipProviderSync) {
            $providerSyncOptions = [
                '--chunk' => $chunk,
                '--limit' => $limit,
                '--stale-minutes' => $staleMinutes,
                '--dry-run' => $dryRun,
            ];

            if ($customerId > 0) {
                $providerSyncOptions['--customer-id'] = $customerId;
            }

            $syncExitCode = $this->call('payments:reconcile-fib-subscriptions', $providerSyncOptions);

            if ($syncExitCode !== self::SUCCESS) {
                $this->error('Provider checkout reconciliation failed.');

                return $syncExitCode;
            }

            $renewalSyncExitCode = $this->call('payments:reconcile-fib-subscription-renewals', $providerSyncOptions);

            if ($renewalSyncExitCode !== self::SUCCESS) {
                $this->error('Provider renewal reconciliation failed.');

                return $renewalSyncExitCode;
            }
        }

        try {
            $defaultServicePlanId = (int) $billingState->defaultServicePlan()->id;
        } catch (ModelNotFoundException) {
            $this->error('Default Free service plan was not found. Please seed or restore service_plans before running reconciliation.');

            return self::FAILURE;
        }

        try {
            $defaultStoragePlanId = (int) $billingState->defaultStoragePlan()->id;
        } catch (ModelNotFoundException) {
            $this->error('Default Free storage plan was not found. Please seed or restore storage_plans before running reconciliation.');

            return self::FAILURE;
        }
        $threshold = now()->subMinutes($graceMinutes);
        $summary = [
            'service_candidates' => 0,
            'service_skipped_missing_metadata' => 0,
            'service_updated' => 0,
            'storage_candidates' => 0,
            'storage_skipped_missing_metadata' => 0,
            'storage_updated' => 0,
        ];

        $remaining = $limit;

        $serviceResult = $this->reconcileLocalOverdue(
            modelClass: CustomerServiceSubscription::class,
            scopeLabel: 'service',
            chunk: $chunk,
            customerId: $customerId,
            threshold: $threshold,
            dryRun: $dryRun,
            events: $events,
            limit: $remaining,
            defaultPlanId: $defaultServicePlanId,
        );
        $summary['service_candidates'] = $serviceResult['candidates'];
        $summary['service_skipped_missing_metadata'] = $serviceResult['skipped_missing_metadata'];
        $summary['service_updated'] = $serviceResult['updated'];

        if ($limit > 0) {
            $remaining = max(0, $remaining - $serviceResult['processed']);
        }

        $storageResult = $this->reconcileLocalOverdue(
            modelClass: CustomerStorageSubscription::class,
            scopeLabel: 'storage',
            chunk: $chunk,
            customerId: $customerId,
            threshold: $threshold,
            dryRun: $dryRun,
            events: $events,
            limit: $remaining,
            defaultPlanId: $defaultStoragePlanId,
        );
        $summary['storage_candidates'] = $storageResult['candidates'];
        $summary['storage_skipped_missing_metadata'] = $storageResult['skipped_missing_metadata'];
        $summary['storage_updated'] = $storageResult['updated'];

        $this->info($dryRun
            ? 'Subscription reconciliation dry-run completed.'
            : 'Subscription reconciliation completed.');
        $this->line('Service candidates: '.number_format($summary['service_candidates']));
        $this->line('Service skipped missing renewal metadata: '.number_format($summary['service_skipped_missing_metadata']));
        $this->line($dryRun
            ? 'Service would downgrade: '.number_format($summary['service_updated'])
            : 'Service downgraded: '.number_format($summary['service_updated']));
        $this->line('Storage candidates: '.number_format($summary['storage_candidates']));
        $this->line('Storage skipped missing renewal metadata: '.number_format($summary['storage_skipped_missing_metadata']));
        $this->line($dryRun
            ? 'Storage would downgrade: '.number_format($summary['storage_updated'])
            : 'Storage downgraded: '.number_format($summary['storage_updated']));

        return self::SUCCESS;
    }

    /**
     * @return array{candidates:int, processed:int, updated:int, skipped_missing_metadata:int}
     */
    protected function reconcileLocalOverdue(
        string $modelClass,
        string $scopeLabel,
        int $chunk,
        int $customerId,
        CarbonInterface $threshold,
        bool $dryRun,
        PaymentEventRecorder $events,
        int $limit = 0,
        int $defaultPlanId = 0,
    ): array {
        $query = $this->overdueQuery($modelClass, $customerId, $threshold, $defaultPlanId);
        $queryCount = (clone $query)->count();
        $candidates = 0;
        $processed = 0;
        $updated = 0;
        $skippedMissingMetadata = 0;
        $previewPrinted = 0;

        if ($queryCount === 0) {
            return [
                'candidates' => 0,
                'processed' => 0,
                'updated' => 0,
                'skipped_missing_metadata' => 0,
            ];
        }

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($subscriptions) use (
                $modelClass,
                $scopeLabel,
                $threshold,
                $dryRun,
                $events,
                $limit,
                $defaultPlanId,
                &$candidates,
                &$processed,
                &$updated,
                &$skippedMissingMetadata,
                &$previewPrinted
            ) {
                foreach ($subscriptions as $subscription) {
                    $disposition = $this->localOverdueDisposition($subscription, $threshold);

                    if ($disposition === 'skip') {
                        continue;
                    }

                    $candidates++;

                    $payment = $subscription->payment;

                    if ($dryRun) {
                        if ($previewPrinted < 20) {
                            $previewPrinted++;
                            $this->line(sprintf(
                                '[dry-run] %s subscription_id=%d customer_id=%d payment_id=%d active_until=%s provider_status=%s',
                                $scopeLabel,
                                (int) $subscription->id,
                                (int) $subscription->customer_id,
                                (int) ($payment?->id ?? 0),
                                (string) ($payment?->active_until?->toIso8601String() ?? 'n/a'),
                                (string) ($payment?->providerStatusLabel() ?? 'n/a'),
                            ));
                        }

                        if ($disposition === 'missing_metadata') {
                            $skippedMissingMetadata++;
                        }

                        if ($disposition === 'downgrade') {
                            $updated++;
                        }

                        continue;
                    }

                    if ($disposition === 'missing_metadata') {
                        $skippedMissingMetadata++;

                        $this->markRenewalMetadataMissing(
                            modelClass: $modelClass,
                            subscriptionId: (int) $subscription->id,
                            scopeLabel: $scopeLabel,
                            events: $events,
                        );

                        continue;
                    }

                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    if ($this->markOverdueSubscriptionEnded(
                        modelClass: $modelClass,
                        subscriptionId: (int) $subscription->id,
                        scopeLabel: $scopeLabel,
                        threshold: $threshold,
                        events: $events,
                        defaultPlanId: $defaultPlanId,
                    )) {
                        $updated++;
                    }
                }

                return true;
            });

        return [
            'candidates' => $candidates,
            'processed' => $processed,
            'updated' => $updated,
            'skipped_missing_metadata' => $skippedMissingMetadata,
        ];
    }

    protected function overdueQuery(string $modelClass, int $customerId, CarbonInterface $threshold, int $defaultPlanId): Builder
    {
        $query = $modelClass::query()
            ->with('payment')
            ->where('status', 'active')
            ->where(function ($starts) {
                $starts->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($candidateWindow) use ($threshold) {
                $candidateWindow
                    ->where(function ($q) use ($threshold) {
                        $q->whereNotNull('ends_at')->where('ends_at', '<=', $threshold);
                    })
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNotNull('cycle_ends_on')->where('cycle_ends_on', '<=', $threshold->toDateString());
                    })
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNotNull('next_renewal_on')->where('next_renewal_on', '<=', $threshold->toDateString());
                    })
                    ->orWhereHas('payment', function ($payment) use ($threshold) {
                        $payment
                            ->whereNotNull('active_until')
                            ->where('active_until', '<=', $threshold);
                    })
                    ->orWhereHas('payment', function ($payment) {
                        $this->applyMissingRenewalMetadataPaymentScope($payment);
                    });
            });

        if ($defaultPlanId > 0) {
            if ($modelClass === CustomerServiceSubscription::class) {
                $query->where('service_plan_id', '!=', $defaultPlanId);
            }

            if ($modelClass === CustomerStorageSubscription::class) {
                $query->where('storage_plan_id', '!=', $defaultPlanId);
            }
        }

        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        return $query;
    }

    protected function markOverdueSubscriptionEnded(
        string $modelClass,
        int $subscriptionId,
        string $scopeLabel,
        CarbonInterface $threshold,
        PaymentEventRecorder $events,
        int $defaultPlanId = 0,
    ): bool {
        $subscription = $modelClass::find($subscriptionId);

        return $subscription && app(\App\Services\Billing\ExpireSubscription::class)->handle($subscription, $threshold);
    }

    protected function isOverdueForDowngrade(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        CarbonInterface $threshold
    ): bool {
        $periodEnd = $this->resolvePeriodEnd($subscription);

        return app(\App\Services\Billing\SubscriptionCyclePolicy::class)->isCurrent($subscription)
            && $periodEnd && $periodEnd->lte($threshold);
    }

    protected function localOverdueDisposition(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        CarbonInterface $threshold
    ): string {
        if ($this->hasMissingRenewalMetadata($subscription)) {
            return 'missing_metadata';
        }

        return $this->isOverdueForDowngrade($subscription, $threshold)
            ? 'downgrade'
            : 'skip';
    }

    protected function applyMissingRenewalMetadataPaymentScope(Builder $payment): void
    {
        $payment
            ->where('provider', PaymentProvider::FIB->value)
            ->where('payment_mode', PaymentMode::RECURRING->value)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION->value)
            ->where('status', PaymentStatus::PAID->value)
            ->where(function ($applied) {
                $applied
                    ->whereNotNull('fulfilled_at')
                    ->orWhere('internal_status', PaymentInternalStatus::APPLIED->value);
            })
            ->whereNull('active_until')
            ->where(function ($status) {
                $status
                    ->whereIn('provider_subscription_status', ['ACTIVE', 'PAID', 'SUBSCRIBED'])
                    ->orWhere(function ($fallback) {
                        $fallback
                            ->whereNull('provider_subscription_status')
                            ->whereIn('provider_status', ['ACTIVE', 'PAID', 'SUBSCRIBED']);
                    });
            });
    }

    protected function hasMissingRenewalMetadata(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription
    ): bool {
        $payment = $subscription->payment;

        if (! $payment instanceof Payment) {
            return false;
        }

        if ($payment->provider !== PaymentProvider::FIB
            || ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT) !== PaymentProviderObjectType::SUBSCRIPTION
            || ! $payment->resolvedPaymentMode()->isRecurring()
            || $payment->status !== PaymentStatus::PAID
            || ! $payment->isApplied()) {
            return false;
        }

        $renewalStrategy = strtolower(trim((string) (
            $subscription->renewal_strategy
            ?? data_get($payment->purchase_snapshot, 'renewal_strategy', PaymentRecurringStrategy::NONE->value)
        )));

        if ($renewalStrategy !== PaymentRecurringStrategy::PROVIDER_SCHEDULE->value) {
            return false;
        }

        $providerStatus = strtoupper(trim((string) ($payment->providerStatusLabel() ?? '')));

        if (! in_array($providerStatus, ['ACTIVE', 'PAID', 'SUBSCRIBED'], true)) {
            return false;
        }

        return $this->resolvePeriodEnd($subscription) === null;
    }

    protected function resolvePeriodEnd(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription
    ): ?CarbonInterface {
        return app(\App\Services\Billing\SubscriptionCyclePolicy::class)->boundary($subscription);
    }

    protected function markRenewalMetadataMissing(
        string $modelClass,
        int $subscriptionId,
        string $scopeLabel,
        PaymentEventRecorder $events,
    ): void {
        DB::transaction(function () use ($modelClass, $subscriptionId, $scopeLabel, $events) {
            /** @var CustomerServiceSubscription|CustomerStorageSubscription|null $subscription */
            $subscription = $modelClass::query()
                ->with('payment')
                ->lockForUpdate()
                ->find($subscriptionId);

            if (! $subscription instanceof CustomerServiceSubscription
                && ! $subscription instanceof CustomerStorageSubscription) {
                return;
            }

            if (! $this->hasMissingRenewalMetadata($subscription)) {
                return;
            }

            $payment = $subscription->payment;
            $now = now();
            $providerStatus = $payment?->providerStatusLabel();
            $meta = (array) ($subscription->meta ?? []);
            $firstDetectedAt = (string) data_get($meta, 'renewal_metadata_missing_detected_at', $now->toIso8601String());
            $meta['renewal_metadata_missing'] = true;
            $meta['renewal_metadata_missing_reason'] = 'provider_active_without_active_until';
            $meta['renewal_metadata_missing_detected_at'] = $firstDetectedAt;
            $meta['renewal_metadata_missing_last_seen_at'] = $now->toIso8601String();
            $meta['renewal_metadata_missing_provider_status'] = $providerStatus;
            $meta['renewal_metadata_missing_source'] = 'subscriptions:reconcile';

            $subscription->forceFill(['meta' => $meta])->save();

            if (! $payment instanceof Payment) {
                return;
            }

            $paymentMeta = (array) ($payment->meta ?? []);
            $paymentMeta['renewal_metadata_missing'] = [
                'state' => true,
                'reason' => 'provider_active_without_active_until',
                'first_detected_at' => (string) data_get($paymentMeta, 'renewal_metadata_missing.first_detected_at', $firstDetectedAt),
                'last_seen_at' => $now->toIso8601String(),
                'provider_status' => $providerStatus,
                'source' => 'subscriptions:reconcile',
            ];
            $payment->forceFill(['meta' => $paymentMeta])->save();

            $events->record($payment, [
                'event_type' => 'subscription_renewal_metadata_missing',
                'source' => 'scheduled_reconciliation_metadata_guard',
                'event_key' => 'subscription-renewal-metadata-missing:'.$scopeLabel.':'.$subscription->id.':'.$now->format('Ymd'),
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'payment_id' => (int) $payment->id,
                    'subscription_id' => (int) $subscription->id,
                    'reason' => 'provider_active_without_active_until',
                ],
            ]);
        }, 3);
    }
}

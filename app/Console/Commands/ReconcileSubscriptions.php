<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\CustomerBillingStateService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
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
            'service_updated' => 0,
            'storage_candidates' => 0,
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
        $summary['storage_updated'] = $storageResult['updated'];

        $this->info($dryRun
            ? 'Subscription reconciliation dry-run completed.'
            : 'Subscription reconciliation completed.');
        $this->line('Service candidates: '.number_format($summary['service_candidates']));
        $this->line($dryRun
            ? 'Service would downgrade: '.number_format($summary['service_updated'])
            : 'Service downgraded: '.number_format($summary['service_updated']));
        $this->line('Storage candidates: '.number_format($summary['storage_candidates']));
        $this->line($dryRun
            ? 'Storage would downgrade: '.number_format($summary['storage_updated'])
            : 'Storage downgraded: '.number_format($summary['storage_updated']));

        return self::SUCCESS;
    }

    /**
     * @return array{candidates:int, processed:int, updated:int}
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
        $previewPrinted = 0;

        if ($queryCount === 0) {
            return [
                'candidates' => 0,
                'processed' => 0,
                'updated' => 0,
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
                &$previewPrinted
            ) {
                foreach ($subscriptions as $subscription) {
                    if (! $this->isOverdueForDowngrade($subscription, $threshold)) {
                        continue;
                    }

                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $processed++;
                    $candidates++;

                    $payment = $subscription->payment;

                    if ($dryRun) {
                        $updated++;

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

                        continue;
                    }

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
        return (bool) DB::transaction(function () use ($modelClass, $subscriptionId, $scopeLabel, $threshold, $events, $defaultPlanId) {
            /** @var CustomerServiceSubscription|CustomerStorageSubscription|null $subscription */
            $subscription = $modelClass::query()
                ->with('payment')
                ->lockForUpdate()
                ->find($subscriptionId);

            if (! $subscription instanceof CustomerServiceSubscription
                && ! $subscription instanceof CustomerStorageSubscription) {
                return false;
            }

            if ($subscription->status !== 'active') {
                return false;
            }

            if ($defaultPlanId > 0) {
                if ($subscription instanceof CustomerServiceSubscription && (int) $subscription->service_plan_id === $defaultPlanId) {
                    return false;
                }

                if ($subscription instanceof CustomerStorageSubscription && (int) $subscription->storage_plan_id === $defaultPlanId) {
                    return false;
                }
            }

            if (! $this->isOverdueForDowngrade($subscription, $threshold)) {
                return false;
            }

            $payment = $subscription->payment;
            $endedAt = $this->resolvePeriodEnd($subscription) ?? now();
            $meta = (array) ($subscription->meta ?? []);
            $meta['cancel_source'] = 'renewal_failed';
            $meta['provider_status'] = $payment?->providerStatusLabel();
            $meta['provider_active_until'] = $payment?->active_until?->toIso8601String();
            $meta['provider_last_payment_at'] = $payment?->last_payment_at?->toIso8601String();
            $meta['provider_lifecycle_synced_at'] = now()->toIso8601String();
            $meta['provider_lifecycle_sync_source'] = 'scheduled_reconciliation_local_expiry';

            $subscription->forceFill([
                'status' => 'ended',
                'auto_renew' => false,
                'ends_at' => $subscription->ends_at ?? $endedAt,
                'canceled_at' => $subscription->canceled_at ?? now(),
                'cycle_ends_on' => $endedAt->toDateString(),
                'next_renewal_on' => $endedAt->toDateString(),
                'meta' => $meta,
            ])->save();

            if ($payment) {
                $events->record($payment, [
                    'event_type' => $scopeLabel.'_subscription_ended',
                    'source' => 'scheduled_reconciliation_local_expiry',
                    'event_key' => 'subscription-local-expiry:'.$scopeLabel.':'.$subscription->id.':'.sha1($endedAt->toIso8601String()),
                    'before_status' => $payment->status->value,
                    'after_status' => $payment->status->value,
                    'meta' => [
                        'provider_status' => $payment->providerStatusLabel(),
                        'period_ends_at' => $endedAt->toIso8601String(),
                        'sync_source' => 'scheduled_reconciliation_local_expiry',
                    ],
                ]);
            }

            if ($defaultPlanId > 0) {
                $this->ensureDefaultSubscriptionActive(
                    modelClass: $modelClass,
                    customerId: (int) $subscription->customer_id,
                    defaultPlanId: $defaultPlanId,
                    endedSubscriptionId: (int) $subscription->id,
                    activatedAt: now(),
                );
            }

            return true;
        }, 3);
    }

    protected function isOverdueForDowngrade(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        CarbonInterface $threshold
    ): bool {
        $periodEnd = $this->resolvePeriodEnd($subscription);

        if (! $periodEnd instanceof CarbonInterface) {
            return false;
        }

        if ($periodEnd->gt($threshold)) {
            return false;
        }

        if ($this->hasSuccessfulRenewalExtendingPeriod($subscription, $periodEnd)) {
            return false;
        }

        return true;
    }

    protected function hasSuccessfulRenewalExtendingPeriod(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        CarbonInterface $periodEnd
    ): bool {
        $isService = $subscription instanceof CustomerServiceSubscription;
        $purchaseType = $isService ? PurchaseType::PLAN_SUBSCRIPTION : PurchaseType::STORAGE_SUBSCRIPTION;
        $purchasableType = $isService ? ServicePlan::class : StoragePlan::class;
        $purchasableId = $isService
            ? (int) ($subscription->service_plan_id ?? 0)
            : (int) ($subscription->storage_plan_id ?? 0);
        $currentPaymentId = (int) ($subscription->payment_id ?? 0);

        $query = Payment::query()
            ->where('customer_id', (int) $subscription->customer_id)
            ->where('purchase_type', $purchaseType->value)
            ->where('status', PaymentStatus::PAID->value)
            ->where(function ($renewed) use ($periodEnd) {
                $renewed
                    ->where(function ($activeUntil) use ($periodEnd) {
                        $activeUntil->whereNotNull('active_until')->where('active_until', '>', $periodEnd);
                    })
                    ->orWhere(function ($lastPayment) use ($periodEnd) {
                        $lastPayment->whereNotNull('last_payment_at')->where('last_payment_at', '>', $periodEnd);
                    })
                    ->orWhere(function ($paidAt) use ($periodEnd) {
                        $paidAt->whereNotNull('paid_at')->where('paid_at', '>', $periodEnd);
                    });
            });

        if ($purchasableId > 0) {
            $query
                ->where('purchasable_type', $purchasableType)
                ->where('purchasable_id', $purchasableId);
        }

        if ($currentPaymentId > 0) {
            $query->where('id', '!=', $currentPaymentId);
        }

        return $query->exists();
    }

    protected function resolvePeriodEnd(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription
    ): ?CarbonInterface {
        if ($subscription->ends_at instanceof CarbonInterface) {
            return $subscription->ends_at;
        }

        foreach (['period_ends_at', 'provider_active_until'] as $key) {
            $value = data_get($subscription->meta, $key);

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            try {
                return Carbon::parse((string) $value);
            } catch (\Throwable) {
                continue;
            }
        }

        if ($subscription->cycle_ends_on instanceof CarbonInterface) {
            return $subscription->cycle_ends_on->copy()->endOfDay();
        }

        if ($subscription->next_renewal_on instanceof CarbonInterface) {
            return $subscription->next_renewal_on->copy()->endOfDay();
        }

        $payment = $subscription->payment;

        if ($payment && $payment->active_until instanceof CarbonInterface) {
            return Carbon::instance($payment->active_until);
        }

        return null;
    }

    protected function ensureDefaultSubscriptionActive(
        string $modelClass,
        int $customerId,
        int $defaultPlanId,
        int $endedSubscriptionId,
        CarbonInterface $activatedAt
    ): void {
        if ($defaultPlanId <= 0 || $customerId <= 0) {
            return;
        }

        if ($modelClass === CustomerServiceSubscription::class) {
            $hasActive = CustomerServiceSubscription::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->exists();

            if ($hasActive) {
                return;
            }

            $cycleEnd = $activatedAt->copy()->addMonthNoOverflow()->subDay();

            CustomerServiceSubscription::query()->create([
                'customer_id' => $customerId,
                'payment_id' => null,
                'service_plan_id' => $defaultPlanId,
                'status' => 'active',
                'source' => 'system',
                'starts_at' => $activatedAt,
                'cycle_started_on' => $activatedAt->toDateString(),
                'cycle_ends_on' => $cycleEnd->toDateString(),
                'next_renewal_on' => $cycleEnd->toDateString(),
                'auto_renew' => false,
                'renewal_strategy' => PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                'meta' => [
                    'activated_by' => 'subscriptions:reconcile',
                    'activated_reason' => 'renewal_failed_downgrade',
                    'activated_at' => $activatedAt->toIso8601String(),
                    'from_subscription_id' => $endedSubscriptionId,
                ],
            ]);

            return;
        }

        if ($modelClass === CustomerStorageSubscription::class) {
            $hasActive = CustomerStorageSubscription::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->exists();

            if ($hasActive) {
                return;
            }

            $cycleEnd = $activatedAt->copy()->addMonthNoOverflow()->subDay();

            CustomerStorageSubscription::query()->create([
                'customer_id' => $customerId,
                'payment_id' => null,
                'storage_plan_id' => $defaultPlanId,
                'status' => 'active',
                'source' => 'system',
                'starts_at' => $activatedAt,
                'cycle_started_on' => $activatedAt->toDateString(),
                'cycle_ends_on' => $cycleEnd->toDateString(),
                'next_renewal_on' => $cycleEnd->toDateString(),
                'auto_renew' => false,
                'renewal_strategy' => PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                'meta' => [
                    'activated_by' => 'subscriptions:reconcile',
                    'activated_reason' => 'renewal_failed_downgrade',
                    'activated_at' => $activatedAt->toIso8601String(),
                    'from_subscription_id' => $endedSubscriptionId,
                ],
            ]);
        }
    }
}

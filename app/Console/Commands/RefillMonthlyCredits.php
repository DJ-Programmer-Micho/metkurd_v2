<?php

namespace App\Console\Commands;

use App\Models\CreditLedger;
use App\Models\CreditMonthlyGrant;
use App\Models\CreditWallet;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RefillMonthlyCredits extends Command
{
    protected $signature = 'credits:refill-monthly
        {--customer= : Refill one customer id only}
        {--limit=0 : Maximum customers to process (0 means no limit)}
        {--chunk=200 : Number of rows per chunk}
        {--dry-run : Show what would change without writing data}';

    protected $description = 'Refill monthly subscription credits once per cycle, safely and idempotently.';

    public function handle(): int
    {
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $customerId = (int) $this->option('customer');
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $yearMonth = $now->format('Y-m');

        $activeLatestIds = CustomerServiceSubscription::query()
            ->selectRaw('MAX(id)')
            ->where('status', 'active')
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->groupBy('customer_id');

        $query = CustomerServiceSubscription::query()
            ->with([
                'servicePlan:id,code,name,monthly_credits,app_monthly_credits,api_monthly_credits,is_active',
                'customer:id,created_at',
            ])
            ->whereIn('id', $activeLatestIds);

        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        $candidateCount = (clone $query)->count();

        if ($candidateCount === 0) {
            $this->info('No active service subscriptions matched for monthly refill.');

            return self::SUCCESS;
        }

        $scanned = 0;
        $dueCount = 0;
        $updated = 0;
        $previewPrinted = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($subscriptions) use (
                $limit,
                $dryRun,
                $yearMonth,
                $now,
                &$scanned,
                &$dueCount,
                &$updated,
                &$previewPrinted
            ) {
                $customerIds = $subscriptions
                    ->pluck('customer_id')
                    ->filter(fn ($id) => (int) $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                $existingGrants = CreditMonthlyGrant::query()
                    ->where('year_month', $yearMonth)
                    ->whereIn('customer_id', $customerIds)
                    ->pluck('id', 'customer_id');

                foreach ($subscriptions as $subscription) {
                    if ($limit > 0 && $dueCount >= $limit) {
                        return false;
                    }

                    $scanned++;

                    $plan = $subscription->servicePlan;

                    if (! $plan instanceof ServicePlan || ! (bool) ($plan->is_active ?? false)) {
                        continue;
                    }

                    $dueAt = $this->dueAtForMonth($subscription, $now);

                    if (! $dueAt instanceof CarbonInterface || $now->lt($dueAt)) {
                        continue;
                    }

                    if ($existingGrants->has((int) $subscription->customer_id)) {
                        continue;
                    }

                    $dueCount++;

                    if ($dryRun) {
                        $updated++;

                        if ($previewPrinted < 20) {
                            $previewPrinted++;
                            $this->line(sprintf(
                                '[dry-run] customer_id=%d plan=%s app_credits=%d api_credits=%d due_at=%s year_month=%s',
                                (int) $subscription->customer_id,
                                (string) $plan->code,
                                max(0, (int) $plan->appMonthlyCredits()),
                                max(0, (int) $plan->apiMonthlyCredits()),
                                (string) $dueAt->toDateString(),
                                $yearMonth
                            ));
                        }

                        continue;
                    }

                    if ($this->refillCustomer($subscription->id, $yearMonth, $dueAt)) {
                        $updated++;
                        $existingGrants->put((int) $subscription->customer_id, 1);
                    }
                }

                return true;
            });

        $this->info($dryRun
            ? 'Monthly credit refill dry-run completed.'
            : 'Monthly credit refill completed.');
        $this->line('Candidates: '.number_format($candidateCount));
        $this->line('Scanned: '.number_format($scanned));
        $this->line('Due: '.number_format($dueCount));
        $this->line($dryRun ? 'Would Refill: '.number_format($updated) : 'Refilled: '.number_format($updated));

        return self::SUCCESS;
    }

    protected function dueAtForMonth(CustomerServiceSubscription $subscription, CarbonInterface $now): ?CarbonInterface
    {
        $anchor = $subscription->starts_at
            ?? $subscription->customer?->created_at
            ?? $subscription->created_at;

        if (! $anchor instanceof CarbonInterface) {
            return null;
        }

        $day = min((int) $anchor->day, (int) $now->daysInMonth);

        return Carbon::create(
            $now->year,
            $now->month,
            $day,
            0,
            0,
            0,
            $now->timezone
        );
    }

    protected function refillCustomer(int $subscriptionId, string $yearMonth, CarbonInterface $dueAt): bool
    {
        return (bool) DB::transaction(function () use ($subscriptionId, $yearMonth, $dueAt) {
            /** @var CustomerServiceSubscription|null $subscription */
            $subscription = CustomerServiceSubscription::query()
                ->with(['servicePlan:id,code,name,monthly_credits,app_monthly_credits,api_monthly_credits,is_active'])
                ->lockForUpdate()
                ->find($subscriptionId);

            if (! $subscription instanceof CustomerServiceSubscription) {
                return false;
            }

            if ($subscription->status !== 'active') {
                return false;
            }

            $now = now();

            if ($subscription->starts_at instanceof CarbonInterface && $subscription->starts_at->isFuture()) {
                return false;
            }

            if ($subscription->ends_at instanceof CarbonInterface && $subscription->ends_at->isPast()) {
                return false;
            }

            $plan = $subscription->servicePlan;

            if (! $plan instanceof ServicePlan || ! (bool) ($plan->is_active ?? false)) {
                return false;
            }

            $existing = CreditMonthlyGrant::query()
                ->where('customer_id', (int) $subscription->customer_id)
                ->where('year_month', $yearMonth)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CreditMonthlyGrant) {
                return false;
            }

            /** @var CreditWallet $wallet */
            $wallet = CreditWallet::query()
                ->where('customer_id', (int) $subscription->customer_id)
                ->where('wallet_type', CreditWallet::TYPE_APP)
                ->lockForUpdate()
                ->first();

            if (! $wallet instanceof CreditWallet) {
                $wallet = CreditWallet::query()->create(
                    CreditWallet::defaultAttributes((int) $subscription->customer_id, CreditWallet::TYPE_APP, $dueAt)
                );

                $wallet = CreditWallet::query()
                    ->whereKey($wallet->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            /** @var CreditWallet $apiWallet */
            $apiWallet = CreditWallet::query()
                ->where('customer_id', (int) $subscription->customer_id)
                ->where('wallet_type', CreditWallet::TYPE_API)
                ->lockForUpdate()
                ->first();

            if (! $apiWallet instanceof CreditWallet) {
                $apiWallet = CreditWallet::query()->create(
                    CreditWallet::defaultAttributes((int) $subscription->customer_id, CreditWallet::TYPE_API, $dueAt)
                );

                $apiWallet = CreditWallet::query()
                    ->whereKey($apiWallet->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $planCredits = max(0, (int) $plan->appMonthlyCredits());
            $apiPlanCredits = max(0, (int) $plan->apiMonthlyCredits());
            $previousCombined = (int) ($wallet->balance_credits ?? 0);
            $previousSubscriptionBalance = (int) ($wallet->subscription_balance_credits ?? 0);
            $addonBalance = (int) ($wallet->addon_balance_credits ?? 0);
            $newSubscriptionBalance = $planCredits;
            $newCombined = $newSubscriptionBalance + $addonBalance;
            $delta = $newCombined - $previousCombined;
            $previousApiCombined = (int) ($apiWallet->balance_credits ?? 0);
            $previousApiSubscriptionBalance = (int) ($apiWallet->subscription_balance_credits ?? 0);
            $apiAddonBalance = (int) ($apiWallet->addon_balance_credits ?? 0);
            $newApiSubscriptionBalance = $apiPlanCredits;
            $newApiCombined = $newApiSubscriptionBalance + $apiAddonBalance;
            $apiDelta = $newApiCombined - $previousApiCombined;
            $nextCycleStart = $dueAt->copy()->addMonthNoOverflow();

            $wallet->forceFill([
                'subscription_balance_credits' => $newSubscriptionBalance,
                'addon_balance_credits' => $addonBalance,
                'balance_credits' => $newCombined,
                'lifetime_earned' => (int) ($wallet->lifetime_earned ?? 0) + max(0, $planCredits),
                'cycle_started_on' => $dueAt->toDateString(),
                'cycle_ends_on' => $nextCycleStart->copy()->subDay()->toDateString(),
                'current_cycle_key' => $yearMonth,
                'last_granted_at' => $now,
            ])->save();

            $apiWallet->forceFill([
                'subscription_balance_credits' => $newApiSubscriptionBalance,
                'addon_balance_credits' => $apiAddonBalance,
                'balance_credits' => $newApiCombined,
                'lifetime_earned' => (int) ($apiWallet->lifetime_earned ?? 0) + max(0, $apiPlanCredits),
                'cycle_started_on' => $dueAt->toDateString(),
                'cycle_ends_on' => $nextCycleStart->copy()->subDay()->toDateString(),
                'current_cycle_key' => $yearMonth,
                'last_granted_at' => $apiPlanCredits > 0 ? $now : $apiWallet->last_granted_at,
            ])->save();

            try {
                $grant = CreditMonthlyGrant::create([
                    'customer_id' => (int) $subscription->customer_id,
                    'service_plan_id' => (int) $plan->id,
                    'subscription_id' => (int) $subscription->id,
                    'year_month' => $yearMonth,
                    'granted_credits' => $planCredits,
                    'granted_at' => $now,
                    'meta' => [
                        'plan_code' => (string) $plan->code,
                        'source' => 'credits:refill-monthly',
                        'due_at' => $dueAt->toDateString(),
                        'app_granted_credits' => $planCredits,
                        'api_granted_credits' => $apiPlanCredits,
                    ],
                ]);
            } catch (QueryException $exception) {
                if (str_contains(strtolower($exception->getMessage()), 'cmg_customer_month_uq')) {
                    return false;
                }

                throw $exception;
            }

            CreditLedger::create([
                'customer_id' => (int) $subscription->customer_id,
                'wallet_type' => CreditWallet::TYPE_APP,
                'type' => 'monthly_refill',
                'source_type' => 'subscription_refill',
                'source_id' => (string) $grant->id,
                'direction' => $delta < 0 ? 'debit' : 'credit',
                'amount' => abs($delta),
                'bucket' => 'combined',
                'credits_delta' => $delta,
                'balance_before' => $previousCombined,
                'balance_after' => $newCombined,
                'subscription_balance_after' => $newSubscriptionBalance,
                'addon_balance_after' => $addonBalance,
                'related_type' => CreditMonthlyGrant::class,
                'related_id' => (string) $grant->id,
                'reference_code' => sprintf('monthly_refill:%d:%s', (int) $subscription->customer_id, $yearMonth),
                'meta' => [
                    'plan_code' => (string) $plan->code,
                    'year_month' => $yearMonth,
                    'subscription_id' => (int) $subscription->id,
                    'previous_balance' => $previousCombined,
                    'previous_subscription_balance' => $previousSubscriptionBalance,
                    'app_granted_credits' => $planCredits,
                    'api_granted_credits' => $apiPlanCredits,
                    'source' => 'credits:refill-monthly',
                ],
            ]);

            CreditLedger::create([
                'customer_id' => (int) $subscription->customer_id,
                'wallet_type' => CreditWallet::TYPE_API,
                'type' => 'monthly_refill',
                'source_type' => 'subscription_refill',
                'source_id' => (string) $grant->id,
                'direction' => $apiDelta < 0 ? 'debit' : 'credit',
                'amount' => abs($apiDelta),
                'bucket' => 'combined',
                'credits_delta' => $apiDelta,
                'balance_before' => $previousApiCombined,
                'balance_after' => $newApiCombined,
                'subscription_balance_after' => $newApiSubscriptionBalance,
                'addon_balance_after' => $apiAddonBalance,
                'related_type' => CreditMonthlyGrant::class,
                'related_id' => (string) $grant->id,
                'reference_code' => sprintf('monthly_refill:api:%d:%s', (int) $subscription->customer_id, $yearMonth),
                'meta' => [
                    'plan_code' => (string) $plan->code,
                    'year_month' => $yearMonth,
                    'subscription_id' => (int) $subscription->id,
                    'previous_balance' => $previousApiCombined,
                    'previous_subscription_balance' => $previousApiSubscriptionBalance,
                    'app_granted_credits' => $planCredits,
                    'api_granted_credits' => $apiPlanCredits,
                    'source' => 'credits:refill-monthly',
                ],
            ]);

            return true;
        }, 3);
    }
}

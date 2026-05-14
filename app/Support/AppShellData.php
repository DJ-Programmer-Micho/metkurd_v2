<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerEntitlement;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\ToolAction;
use App\Services\Billing\CustomerUsageSummaryService;
use App\Services\Plans\PlanConcurrencyService;
use Illuminate\Support\Facades\Cache;

class AppShellData
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected static array $cache = [];

    public static function forgetForCurrentCustomer(): void
    {
        $customerId = (int) auth('app')->id();

        if ($customerId > 0) {
            self::forgetForCustomerId($customerId);
        }
    }

    public static function forgetForCustomerId(int $customerId): void
    {
        unset(self::$cache[$customerId]);

        if ($customerId <= 0) {
            return;
        }

        Cache::forget("app-shell:{$customerId}:access-map");
        Cache::forget("app-shell:{$customerId}:active-jobs");
        Cache::forget("app-shell:{$customerId}:usage-summary");
        Cache::forget("app-shell:{$customerId}:allowed-slots");
    }

    /**
     * @return array<string, mixed>
     */
    public function forCurrentCustomer(bool $forceRefresh = false): array
    {
        $customer = auth('app')->user();

        if (! $customer instanceof Customer) {
            return $this->emptyPayload();
        }

        $customerId = (int) $customer->id;

        if ($forceRefresh) {
            self::forgetForCustomerId($customerId);
        }

        if (isset(self::$cache[$customerId])) {
            return self::$cache[$customerId];
        }

        $customer->loadMissing([
            'profile',
            'usage',
            'wallet',
        ]);

        $servicePlan = method_exists($customer, 'currentServicePlan')
            ? $customer->currentServicePlan()
            : ($customer->servicePlan ?: $customer->activeServiceSubscription?->servicePlan);

        $storageState = method_exists($customer, 'storageQuotaState')
            ? $customer->storageQuotaState()
            : [];
        $storagePlan = $storageState['current_plan']
            ?? (method_exists($customer, 'currentStoragePlan')
                ? $customer->currentStoragePlan()
                : ($customer->storagePlan ?: $customer->activeStorageSubscription?->storagePlan));
        $wallet = $customer->wallet;
        $usage = $customer->usage;
        $usageSummary = Cache::remember(
            "app-shell:{$customerId}:usage-summary",
            now()->addSeconds(10),
            fn () => app(CustomerUsageSummaryService::class)->forCustomer($customer)
        );

        $planCode = strtolower((string) ($customer->serviceCode() ?: 'free'));
        $monthlyCredits = data_get($usageSummary, 'credits.monthly', (int) ($servicePlan?->monthly_credits ?? 0));
        $creditBalance = data_get($usageSummary, 'credits.balance', (int) ($wallet?->balance_credits ?? 0));
        $quotaMb = data_get($usageSummary, 'storage.quota_mb', (int) ($storageState['current_limit_mb'] ?? $storagePlan?->quota_mb ?? 512));
        $usedBytes = data_get($usageSummary, 'storage.used_bytes', (int) ($storageState['used_bytes'] ?? $usage?->storage_used_bytes ?? 0));
        $usedMb = data_get($usageSummary, 'storage.used_mb', (int) ($storageState['used_mb'] ?? round($usedBytes / 1024 / 1024)));
        $allowedSlots = (int) Cache::remember(
            "app-shell:{$customerId}:allowed-slots",
            now()->addSeconds(60),
            fn () => app(PlanConcurrencyService::class)->allowedConcurrentJobsForPlan($servicePlan)
        );

        return self::$cache[$customerId] = [
            'customer' => $customer,
            'profile' => $customer->profile,
            'plan_code' => $planCode,
            'plan_name' => strtoupper($planCode),
            'plan_class' => match ($planCode) {
                'premium' => 'bg-warning text-dark',
                'pro' => 'bg-success',
                'student' => 'bg-info',
                default => 'bg-secondary',
            },
            'access_map' => Cache::remember(
                "app-shell:{$customerId}:access-map",
                now()->addSeconds(60),
                fn () => $this->buildAccessMap($customer)
            ),
            'credit_balance' => $creditBalance,
            'monthly_credits' => $monthlyCredits,
            'credits_pct' => (int) (data_get($usageSummary, 'credits.percent_remaining') ?? 0),
            'storage_quota_mb' => $quotaMb,
            'storage_used_mb' => $usedMb,
            'storage_pct' => (int) (data_get($usageSummary, 'storage.percent_used') ?? 0),
            'storage_over_quota' => (bool) data_get($usageSummary, 'storage.over_quota', $storageState['over_quota'] ?? false),
            'storage_cancellation_scheduled' => (bool) ($storageState['cancellation_scheduled'] ?? false),
            'allowed_slots' => $allowedSlots,
            'active_jobs' => Cache::remember(
                "app-shell:{$customerId}:active-jobs",
                now()->addSeconds(10),
                fn () => $this->activeJobsCount($customerId)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPayload(): array
    {
        return [
            'customer' => null,
            'profile' => null,
            'plan_code' => 'free',
            'plan_name' => 'FREE',
            'plan_class' => 'bg-secondary',
            'access_map' => [],
            'credit_balance' => 0,
            'monthly_credits' => 0,
            'credits_pct' => 0,
            'storage_quota_mb' => 512,
            'storage_used_mb' => 0,
            'storage_pct' => 0,
            'storage_over_quota' => false,
            'storage_cancellation_scheduled' => false,
            'allowed_slots' => 2,
            'active_jobs' => 0,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function buildAccessMap(Customer $customer): array
    {
        $toolCodes = ['tts', 'ftts', 'clone_tts', 'asr', 'qasr', 'caption', 'tran', 'stem', 'ocr', 'youtube_audio', 'youtube_video'];
        $map = array_fill_keys($toolCodes, false);

        $actions = ToolAction::query()
            ->select(['id', 'tool_code'])
            ->whereIn('tool_code', $toolCodes)
            ->where('is_active', true)
            ->whereHas('tool', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();

        if ($actions->isEmpty()) {
            $map['youtube_download'] = false;
            return $map;
        }

        $actionIds = $actions->pluck('id')->all();
        $now = now();

        $overrides = CustomerEntitlement::query()
            ->select(['tool_action_id', 'allowed'])
            ->where('customer_id', (int) $customer->id)
            ->whereIn('tool_action_id', $actionIds)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->get()
            ->keyBy('tool_action_id');

        $planId = method_exists($customer, 'currentServicePlanId')
            ? (int) ($customer->currentServicePlanId() ?? 0)
            : 0;

        $planEntitlements = $planId > 0
            ? PlanEntitlement::query()
                ->select(['tool_action_id', 'allowed'])
                ->where('service_plan_id', $planId)
                ->whereIn('tool_action_id', $actionIds)
                ->get()
                ->keyBy('tool_action_id')
            : collect();

        foreach ($actions as $action) {
            $override = $overrides->get($action->id);
            $toolCode = strtolower(trim((string) $action->tool_code));

            if ($toolCode === '' || !array_key_exists($toolCode, $map)) {
                continue;
            }

            $allowed = $override && $override->allowed !== null
                ? (bool) $override->allowed
                : (bool) data_get($planEntitlements->get($action->id), 'allowed', false);

            if ($allowed) {
                $map[$toolCode] = true;
            }
        }

        $map['youtube_download'] = (bool) ($map['youtube_audio'] ?? false) || (bool) ($map['youtube_video'] ?? false);

        return $map;
    }

    protected function activeJobsCount(int $customerId): int
    {
        return MlJob::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->where(function ($query) {
                $query->where(function ($live) {
                    $live->whereNotNull('lock_expires_at')
                        ->where('lock_expires_at', '>', now());
                })->orWhere(function ($fresh) {
                    $fresh->whereNull('lock_expires_at')
                        ->where('created_at', '>=', now()->subSeconds(15));
                });
            })
            ->count();
    }
}

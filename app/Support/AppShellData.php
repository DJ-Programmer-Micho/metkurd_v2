<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\ToolAction;
use App\Services\Billing\CustomerUsageSummaryService;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\Plans\PlanConcurrencyService;
use Illuminate\Support\Facades\Cache;

class AppShellData
{
    /**
     * @var array<string, array<string, mixed>>
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
        $prefix = $customerId.':';

        foreach (array_keys(self::$cache) as $cacheKey) {
            if ($cacheKey === (string) $customerId || str_starts_with($cacheKey, $prefix)) {
                unset(self::$cache[$cacheKey]);
            }
        }

        if ($customerId <= 0) {
            return;
        }

        Cache::forget("app-shell:{$customerId}:access-map");
        Cache::forget("app-shell:{$customerId}:active-jobs");
        Cache::forget("app-shell:{$customerId}:usage-summary");
        Cache::forget("app-shell:{$customerId}:usage-summary:app");
        Cache::forget("app-shell:{$customerId}:usage-summary:api");
        Cache::forget("app-shell:{$customerId}:allowed-slots");
        $version = self::servicePlanCacheVersion();
        Cache::forget("app-shell:{$customerId}:access-map:v{$version}");
        Cache::forget("app-shell:{$customerId}:usage-summary:app:v{$version}");
        Cache::forget("app-shell:{$customerId}:usage-summary:api:v{$version}");
        Cache::forget("app-shell:{$customerId}:allowed-slots:v{$version}");
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
        $planCacheVersion = self::servicePlanCacheVersion();
        $requestCacheKey = $this->cacheKeyForCustomerId($customerId, $planCacheVersion);

        if ($forceRefresh) {
            self::forgetForCustomerId($customerId);
        }

        if (isset(self::$cache[$requestCacheKey])) {
            return self::$cache[$requestCacheKey];
        }

        $customer->loadMissing([
            'profile',
            'usage',
            'wallet',
            'apiWallet',
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
        $apiAccessService = app(CustomerApiAccessService::class);
        $apiAccessEnabled = $apiAccessService->customerHasApiAccess($customer);
        $appUsageSummary = Cache::remember(
            "app-shell:{$customerId}:usage-summary:app:v{$planCacheVersion}",
            now()->addSeconds(10),
            fn () => app(CustomerUsageSummaryService::class)->forAppCustomer($customer)
        );
        $apiUsageSummary = $apiAccessEnabled
            ? Cache::remember(
                "app-shell:{$customerId}:usage-summary:api:v{$planCacheVersion}",
                now()->addSeconds(10),
                fn () => app(CustomerUsageSummaryService::class)->forApiCustomer($customer)
            )
            : null;

        $planCode = strtolower((string) ($customer->serviceCode() ?: 'free'));
        $monthlyCredits = data_get($appUsageSummary, 'credits.monthly', (int) ($servicePlan?->appMonthlyCredits() ?? $servicePlan?->monthly_credits ?? 0));
        $creditBalance = data_get($appUsageSummary, 'credits.balance', (int) ($wallet?->balance_credits ?? 0));
        $quotaMb = data_get($appUsageSummary, 'storage.quota_mb', (int) ($storageState['current_limit_mb'] ?? $storagePlan?->quota_mb ?? 512));
        $usedBytes = data_get($appUsageSummary, 'storage.used_bytes', (int) ($storageState['used_bytes'] ?? $usage?->storage_used_bytes ?? 0));
        $usedMb = data_get($appUsageSummary, 'storage.used_mb', (int) ($storageState['used_mb'] ?? round($usedBytes / 1024 / 1024)));
        $allowedSlots = (int) Cache::remember(
            "app-shell:{$customerId}:allowed-slots:v{$planCacheVersion}",
            now()->addSeconds(60),
            fn () => app(PlanConcurrencyService::class)->allowedConcurrentJobsForPlan($servicePlan)
        );

        return self::$cache[$requestCacheKey] = [
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
                "app-shell:{$customerId}:access-map:v{$planCacheVersion}",
                now()->addSeconds(60),
                fn () => $this->buildAccessMap($customer)
            ),
            'credit_balance' => $creditBalance,
            'monthly_credits' => $monthlyCredits,
            'credits_pct' => (int) (data_get($appUsageSummary, 'credits.percent_remaining') ?? 0),
            'app_credits' => $this->creditShellSummary(
                $appUsageSummary,
                (int) ($wallet?->balance_credits ?? 0),
                (int) ($servicePlan?->appMonthlyCredits() ?? $servicePlan?->monthly_credits ?? 0)
            ),
            'api_credits' => $apiUsageSummary !== null
                ? $this->creditShellSummary(
                    $apiUsageSummary,
                    (int) ($customer->apiWallet?->balance_credits ?? 0),
                    (int) ($servicePlan?->apiMonthlyCredits() ?? 0)
                )
                : null,
            'storage_quota_mb' => $quotaMb,
            'storage_used_mb' => $usedMb,
            'storage_pct' => (int) (data_get($appUsageSummary, 'storage.percent_used') ?? 0),
            'storage_over_quota' => (bool) data_get($appUsageSummary, 'storage.over_quota', $storageState['over_quota'] ?? false),
            'storage_cancellation_scheduled' => (bool) ($storageState['cancellation_scheduled'] ?? false),
            'api_access_enabled' => $apiAccessEnabled,
            'allowed_slots' => $allowedSlots,
            'active_jobs' => Cache::remember(
                "app-shell:{$customerId}:active-jobs",
                now()->addSeconds(10),
                fn () => $this->activeJobsCount($customerId)
            ),
        ];
    }

    protected function cacheKeyForCustomerId(int $customerId, int $planCacheVersion): string
    {
        if ($customerId <= 0) {
            return '0';
        }

        if (! app()->bound('request')) {
            return "{$customerId}:{$planCacheVersion}";
        }

        return $customerId.':'.$planCacheVersion.':'.spl_object_id(request());
    }

    protected static function servicePlanCacheVersion(): int
    {
        return max(1, (int) Cache::get('service-plans:cache-version', 1));
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
            'app_credits' => $this->creditShellSummary([], 0, 0),
            'api_credits' => null,
            'storage_quota_mb' => 512,
            'storage_used_mb' => 0,
            'storage_pct' => 0,
            'storage_over_quota' => false,
            'storage_cancellation_scheduled' => false,
            'api_access_enabled' => false,
            'allowed_slots' => PlanConcurrencyService::DEFAULT_LIMIT,
            'active_jobs' => 0,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function buildAccessMap(Customer $customer): array
    {
        $toolCodes = ['tts', 'xomni', 'ftts', 'clone_tts', 'clone_xomni', 'asr', 'qasr', 'caption', 'tran', 'stem', 'ocr', 'youtube_audio', 'youtube_video'];
        $map = array_fill_keys($toolCodes, false);

        $actions = ToolAction::query()
            ->select(['id', 'tool_code', 'full_code'])
            ->whereIn('tool_code', $toolCodes)
            ->where('is_active', true)
            ->whereHas('tool', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();

        if ($actions->isEmpty()) {
            $map['youtube_download'] = false;

            return $map;
        }

        foreach ($actions as $action) {
            $toolCode = strtolower(trim((string) $action->tool_code));

            if ($toolCode === '' || ! array_key_exists($toolCode, $map)) {
                continue;
            }

            if ($customer->isAllowed((string) $action->full_code, \App\Models\PlanEntitlement::CHANNEL_APP)) {
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

    /**
     * @param  array<string, mixed>  $usageSummary
     * @return array<string, int|null>
     */
    protected function creditShellSummary(array $usageSummary, int $fallbackBalance, int $fallbackMonthly): array
    {
        return [
            'balance' => (int) data_get($usageSummary, 'credits.balance', $fallbackBalance),
            'monthly' => data_get($usageSummary, 'credits.monthly', $fallbackMonthly),
            'used' => data_get($usageSummary, 'credits.used'),
            'percent_used' => data_get($usageSummary, 'credits.percent_used'),
            'percent_remaining' => data_get($usageSummary, 'credits.percent_remaining'),
        ];
    }
}

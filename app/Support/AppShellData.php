<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerEntitlement;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\ToolAction;
use Illuminate\Support\Facades\Cache;

class AppShellData
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected static array $cache = [];

    /**
     * @return array<string, mixed>
     */
    public function forCurrentCustomer(): array
    {
        $customer = auth('app')->user();

        if (! $customer instanceof Customer) {
            return $this->emptyPayload();
        }

        $customerId = (int) $customer->id;

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

        $storagePlan = method_exists($customer, 'currentStoragePlan')
            ? $customer->currentStoragePlan()
            : ($customer->storagePlan ?: $customer->activeStorageSubscription?->storagePlan);
        $wallet = $customer->wallet;
        $usage = $customer->usage;

        $planCode = strtolower((string) ($customer->serviceCode() ?: 'free'));
        $monthlyCredits = (int) ($servicePlan?->monthly_credits ?? 0);
        $creditBalance = (int) ($wallet?->balance_credits ?? 0);
        $quotaMb = (int) ($storagePlan?->quota_mb ?? 512);
        $usedBytes = (int) ($usage?->storage_used_bytes ?? 0);
        $usedMb = (int) round($usedBytes / 1024 / 1024);
        $allowedSlots = $this->allowedSlotsForPlan($planCode);

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
            'credits_pct' => $monthlyCredits > 0 ? min(100, (int) round(($creditBalance / $monthlyCredits) * 100)) : 0,
            'storage_quota_mb' => $quotaMb,
            'storage_used_mb' => $usedMb,
            'storage_pct' => $quotaMb > 0 ? min(100, (int) round(($usedMb / $quotaMb) * 100)) : 0,
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
            'allowed_slots' => 2,
            'active_jobs' => 0,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function buildAccessMap(Customer $customer): array
    {
        $toolCodes = ['tts', 'ftts', 'clone_tts', 'asr', 'qasr', 'tran', 'stem', 'ocr', 'youtube_audio', 'youtube_video'];
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

    protected function allowedSlotsForPlan(string $planCode): int
    {
        return match ($planCode) {
            'student' => 2,
            'pro' => 3,
            'premium' => 5,
            default => 2,
        };
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

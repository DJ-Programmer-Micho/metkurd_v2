<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\MlJob;

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
            'servicePlan',
            'storagePlan',
            'activeServiceSubscription.servicePlan',
            'activeStorageSubscription.storagePlan',
        ]);

        $servicePlan = $customer->servicePlan ?: $customer->activeServiceSubscription?->servicePlan;
        $storagePlan = $customer->storagePlan ?: $customer->activeStorageSubscription?->storagePlan;
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
            'access_map' => $this->buildAccessMap($customer),
            'credit_balance' => $creditBalance,
            'monthly_credits' => $monthlyCredits,
            'credits_pct' => $monthlyCredits > 0 ? min(100, (int) round(($creditBalance / $monthlyCredits) * 100)) : 0,
            'storage_quota_mb' => $quotaMb,
            'storage_used_mb' => $usedMb,
            'storage_pct' => $quotaMb > 0 ? min(100, (int) round(($usedMb / $quotaMb) * 100)) : 0,
            'allowed_slots' => $allowedSlots,
            'active_jobs' => $this->activeJobsCount($customerId),
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
        $map = [];

        foreach (['tts', 'clone_tts', 'asr', 'stem', 'ocr', 'youtube_audio', 'youtube_video'] as $toolCode) {
            $map[$toolCode] = $customer->canAccessTool($toolCode);
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

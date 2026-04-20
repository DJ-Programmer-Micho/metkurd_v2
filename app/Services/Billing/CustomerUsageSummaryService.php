<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;

class CustomerUsageSummaryService
{
    protected const BYTES_PER_MB = 1048576;

    public function __construct(
        protected CustomerBillingStateService $billingState,
    ) {
    }

    /**
     * @return array{
     *     credits: array{
     *         balance:int,
     *         monthly:?int,
     *         used:?int,
     *         percent_used:?int,
     *         percent_remaining:?int
     *     },
     *     storage: array{
     *         used_bytes:int,
     *         used_mb:int,
     *         quota_bytes:?int,
     *         quota_mb:?int,
     *         remaining_bytes:?int,
     *         remaining_mb:?int,
     *         percent_used:?int,
     *         percent_remaining:?int,
     *         over_quota:bool,
     *         upload_blocked:bool
     *     },
     *     meta: array{
     *         plan_code:string,
     *         plan_name:string
     *     }
     * }
     */
    public function forCustomer(Customer $customer): array
    {
        $customer->loadMissing([
            'usage',
            'wallet',
            'activeServiceSubscription.servicePlan',
            'activeStorageSubscription.storagePlan',
        ]);

        $servicePlan = $customer->currentServicePlan() ?: $this->billingState->defaultServicePlan();
        $storagePlan = $customer->currentStoragePlan() ?: $this->billingState->defaultStoragePlan();
        $storageState = $this->billingState->storageQuotaState($customer);

        $monthlyCredits = $this->monthlyCreditsForPlan($servicePlan);
        $creditBalance = max(0, (int) ($customer->wallet?->balance_credits ?? 0));
        $creditUsed = $monthlyCredits !== null
            ? max($monthlyCredits - $creditBalance, 0)
            : null;
        $creditRemainingPercent = $monthlyCredits !== null
            ? $this->percentage(min($creditBalance, $monthlyCredits), $monthlyCredits)
            : null;
        $creditUsedPercent = $creditRemainingPercent !== null
            ? max(0, 100 - $creditRemainingPercent)
            : null;

        $usedBytes = max(0, (int) ($storageState['used_bytes'] ?? $customer->usage?->storage_used_bytes ?? 0));
        $quotaMb = $this->storageQuotaMbForPlan($storagePlan);
        $quotaBytes = $quotaMb !== null ? $quotaMb * self::BYTES_PER_MB : null;
        $remainingBytes = $quotaBytes !== null ? max($quotaBytes - $usedBytes, 0) : null;
        $storageUsedPercent = $this->percentage($usedBytes, $quotaBytes);
        $storageRemainingPercent = $storageUsedPercent !== null
            ? max(0, 100 - $storageUsedPercent)
            : null;

        $overQuota = $quotaBytes !== null
            ? $usedBytes > $quotaBytes
            : false;
        $uploadBlocked = $quotaBytes !== null
            ? $usedBytes > $quotaBytes
            : false;

        return [
            'credits' => [
                'balance' => $creditBalance,
                'monthly' => $monthlyCredits,
                'used' => $creditUsed,
                'percent_used' => $creditUsedPercent,
                'percent_remaining' => $creditRemainingPercent,
            ],
            'storage' => [
                'used_bytes' => $usedBytes,
                'used_mb' => $this->bytesToMbFloor($usedBytes),
                'quota_bytes' => $quotaBytes,
                'quota_mb' => $quotaMb,
                'remaining_bytes' => $remainingBytes,
                'remaining_mb' => $remainingBytes !== null ? $this->bytesToMbFloor($remainingBytes) : null,
                'percent_used' => $storageUsedPercent,
                'percent_remaining' => $storageRemainingPercent,
                'over_quota' => $overQuota,
                'upload_blocked' => $uploadBlocked,
            ],
            'meta' => [
                'plan_code' => (string) ($servicePlan?->code ?? 'free'),
                'plan_name' => (string) ($servicePlan?->name ?? 'Free'),
            ],
        ];
    }

    protected function monthlyCreditsForPlan(?ServicePlan $plan): ?int
    {
        if (! $plan instanceof ServicePlan) {
            return 0;
        }

        if ((bool) data_get((array) ($plan->meta ?? []), 'unlimited_credits', false)) {
            return null;
        }

        $value = $plan->getAttribute('monthly_credits');

        return $value === null ? null : max(0, (int) $value);
    }

    protected function storageQuotaMbForPlan(?StoragePlan $plan): ?int
    {
        if (! $plan instanceof StoragePlan) {
            return 512;
        }

        $value = $plan->getAttribute('quota_mb');

        return $value === null ? null : max(0, (int) $value);
    }

    protected function percentage(int $part, ?int $whole): ?int
    {
        if ($whole === null || $whole <= 0) {
            return null;
        }

        return max(0, min(100, (int) round(($part / $whole) * 100)));
    }

    protected function bytesToMbFloor(int $bytes): int
    {
        return (int) floor(max(0, $bytes) / self::BYTES_PER_MB);
    }
}

<?php

namespace App\Services\Billing;

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;

class CustomerUsageSummaryService
{
    protected const BYTES_PER_MB = 1048576;

    public function __construct(
        protected CustomerBillingStateService $billingState,
    ) {}

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
    public function forCustomer(Customer $customer, string $walletType = CreditWallet::TYPE_APP): array
    {
        $walletRelation = $walletType === CreditWallet::TYPE_API ? 'apiWallet' : 'wallet';

        $customer->loadMissing([
            'usage',
            $walletRelation,
            'activeServiceSubscription.servicePlan',
            'activeStorageSubscription.storagePlan',
        ]);

        $serviceState = $this->billingState->servicePlanState($customer);
        $servicePlan = $serviceState['current_plan'];
        $storagePlan = $this->resolveFreshStoragePlan($customer);
        $storageState = $this->billingState->storageQuotaState($customer);
        $wallet = $this->walletForCustomer($customer, $walletType);

        $monthlyCredits = $serviceState['agreement']
            ? (int) $serviceState['allowances'][$walletType]
            : $this->monthlyCreditsForPlan($servicePlan, $walletType);
        $creditBalance = max(0, (int) ($wallet?->balance_credits ?? 0));
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
                'wallet_type' => $walletType,
            ],
        ];
    }

    public function forApiCustomer(Customer $customer): array
    {
        return $this->forCustomer($customer, CreditWallet::TYPE_API);
    }

    public function forAppCustomer(Customer $customer): array
    {
        return $this->forCustomer($customer, CreditWallet::TYPE_APP);
    }

    protected function monthlyCreditsForPlan(?ServicePlan $plan, string $walletType): ?int
    {
        if (! $plan instanceof ServicePlan) {
            return 0;
        }

        if ((bool) data_get((array) ($plan->meta ?? []), 'unlimited_credits', false)) {
            return null;
        }

        $value = $walletType === CreditWallet::TYPE_API
            ? $plan->apiMonthlyCredits()
            : $plan->appMonthlyCredits();

        return $value === null ? null : max(0, (int) $value);
    }

    protected function walletForCustomer(Customer $customer, string $walletType): ?CreditWallet
    {
        return $walletType === CreditWallet::TYPE_API ? $customer->apiWallet()->first() : $customer->wallet()->first();
    }

    protected function storageQuotaMbForPlan(?StoragePlan $plan): ?int
    {
        if (! $plan instanceof StoragePlan) {
            return 512;
        }

        $value = $plan->getAttribute('quota_mb');

        return $value === null ? null : max(0, (int) $value);
    }

    protected function resolveFreshStoragePlan(Customer $customer): ?StoragePlan
    {
        $planId = (int) ($customer->currentStoragePlan()?->id ?? 0);

        if ($planId > 0) {
            return StoragePlan::query()->find($planId) ?: $this->billingState->defaultStoragePlan();
        }

        return $this->billingState->defaultStoragePlan();
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

<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Carbon\CarbonInterface;

class CustomerBillingStateService
{
    protected ?ServicePlan $defaultServicePlan = null;

    protected ?StoragePlan $defaultStoragePlan = null;

    /**
     * @return array{
     *     subscription:?CustomerServiceSubscription,
     *     current_plan:ServicePlan,
     *     default_plan:ServicePlan,
     *     current_plan_id:int,
     *     has_active_paid_main_plan:bool,
     *     should_hide_free_plan:bool,
     *     cancelable:bool,
     *     cancellation_scheduled:bool,
     *     period_ends_at:?CarbonInterface,
     *     scheduled_plan:?ServicePlan
     * }
     */
    public function servicePlanState(Customer $customer): array
    {
        $defaultPlan = $this->defaultServicePlan();
        $subscription = $this->resolveActiveServiceSubscription($customer);
        $currentPlan = $subscription?->servicePlan instanceof ServicePlan
            ? $subscription->servicePlan
            : $defaultPlan;
        $periodEndsAt = $this->resolveSubscriptionPeriodEnd($subscription);
        $cancellationScheduled = $subscription instanceof CustomerServiceSubscription
            && $subscription->canceled_at !== null
            && $subscription->ends_at instanceof CarbonInterface
            && $subscription->ends_at->isFuture();
        $hasPaidPlan = $subscription instanceof CustomerServiceSubscription
            && ! (bool) ($currentPlan->is_free ?? false);

        return [
            'subscription' => $subscription,
            'current_plan' => $currentPlan,
            'default_plan' => $defaultPlan,
            'current_plan_id' => (int) $currentPlan->id,
            'has_active_paid_main_plan' => $hasPaidPlan,
            'should_hide_free_plan' => $hasPaidPlan,
            'cancelable' => $hasPaidPlan && ! $cancellationScheduled,
            'cancellation_scheduled' => $cancellationScheduled,
            'period_ends_at' => $periodEndsAt,
            'scheduled_plan' => $cancellationScheduled ? $defaultPlan : null,
        ];
    }

    /**
     * @return array{
     *     subscription:?CustomerStorageSubscription,
     *     current_plan:StoragePlan,
     *     default_plan:StoragePlan,
     *     current_plan_id:int,
     *     has_paid_storage_plan:bool,
     *     cancelable:bool,
     *     cancellation_scheduled:bool,
     *     period_ends_at:?CarbonInterface,
     *     scheduled_plan:?StoragePlan,
     *     current_limit_mb:int,
     *     current_limit_bytes:int,
     *     future_limit_mb:int,
     *     future_limit_bytes:int,
     *     used_bytes:int,
     *     used_mb:int,
     *     over_quota:bool,
     *     upload_blocked:bool,
     *     storage_growth_blocked:bool,
     *     projected_over_quota_after_downgrade:bool
     * }
     */
    public function storageQuotaState(Customer $customer): array
    {
        $defaultPlan = $this->defaultStoragePlan();
        $subscription = $this->resolveActiveStorageSubscription($customer);
        $currentPlan = $subscription?->storagePlan instanceof StoragePlan
            ? $subscription->storagePlan
            : $defaultPlan;
        $usedBytes = (int) ($customer->usage?->storage_used_bytes
            ?? $customer->usage()->value('storage_used_bytes')
            ?? 0);
        $currentLimitMb = max(1, (int) ($currentPlan->quota_mb ?? 512));
        $currentLimitBytes = $currentLimitMb * 1024 * 1024;
        $periodEndsAt = $this->resolveSubscriptionPeriodEnd($subscription);
        $cancellationScheduled = $subscription instanceof CustomerStorageSubscription
            && $subscription->canceled_at !== null
            && $subscription->ends_at instanceof CarbonInterface
            && $subscription->ends_at->isFuture();
        $hasPaidStoragePlan = $subscription instanceof CustomerStorageSubscription
            && (int) $currentPlan->id !== (int) $defaultPlan->id;
        $scheduledPlan = $cancellationScheduled ? $defaultPlan : null;
        $downgradePlan = $hasPaidStoragePlan
            ? $defaultPlan
            : $currentPlan;
        $futureLimitMb = $downgradePlan instanceof StoragePlan
            ? max(1, (int) ($downgradePlan->quota_mb ?? $defaultPlan->quota_mb ?? 512))
            : $currentLimitMb;
        $futureLimitBytes = $futureLimitMb * 1024 * 1024;

        return [
            'subscription' => $subscription,
            'current_plan' => $currentPlan,
            'default_plan' => $defaultPlan,
            'current_plan_id' => (int) $currentPlan->id,
            'has_paid_storage_plan' => $hasPaidStoragePlan,
            'cancelable' => $hasPaidStoragePlan && ! $cancellationScheduled,
            'cancellation_scheduled' => $cancellationScheduled,
            'period_ends_at' => $periodEndsAt,
            'scheduled_plan' => $scheduledPlan,
            'current_limit_mb' => $currentLimitMb,
            'current_limit_bytes' => $currentLimitBytes,
            'future_limit_mb' => $futureLimitMb,
            'future_limit_bytes' => $futureLimitBytes,
            'used_bytes' => $usedBytes,
            'used_mb' => (int) floor($usedBytes / 1024 / 1024),
            'over_quota' => $usedBytes > $currentLimitBytes,
            'upload_blocked' => $usedBytes > $currentLimitBytes,
            'storage_growth_blocked' => $usedBytes > $currentLimitBytes,
            'projected_over_quota_after_downgrade' => $usedBytes > $futureLimitBytes,
        ];
    }

    public function defaultServicePlan(): ServicePlan
    {
        if ($this->defaultServicePlan instanceof ServicePlan) {
            return $this->defaultServicePlan;
        }

        return $this->defaultServicePlan = ServicePlan::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('code', 'free')
                    ->orWhere('is_free', true);
            })
            ->orderByRaw("CASE WHEN code = 'free' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();
    }

    public function defaultStoragePlan(): StoragePlan
    {
        if ($this->defaultStoragePlan instanceof StoragePlan) {
            return $this->defaultStoragePlan;
        }

        return $this->defaultStoragePlan = StoragePlan::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('code', 'free-512')
                    ->orWhere('price_iqd', 0)
                    ->orWhere('price_usd', 0);
            })
            ->orderByRaw("CASE WHEN code = 'free-512' THEN 0 ELSE 1 END")
            ->orderBy('quota_mb')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();
    }

    public function resolveActiveServiceSubscription(Customer $customer): ?CustomerServiceSubscription
    {
        $subscription = CustomerServiceSubscription::query()
            ->with('servicePlan')
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        $customer->setRelation('activeServiceSubscription', $subscription);

        if ($subscription?->servicePlan instanceof ServicePlan) {
            $customer->setRelation('servicePlan', $subscription->servicePlan);
        }

        return $subscription;
    }

    public function resolveActiveStorageSubscription(Customer $customer): ?CustomerStorageSubscription
    {
        $subscription = CustomerStorageSubscription::query()
            ->with('storagePlan')
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        $customer->setRelation('activeStorageSubscription', $subscription);

        if ($subscription?->storagePlan instanceof StoragePlan) {
            $customer->setRelation('storagePlan', $subscription->storagePlan);
        }

        return $subscription;
    }

    public function resolveSubscriptionPeriodEnd(CustomerServiceSubscription|CustomerStorageSubscription|null $subscription): ?CarbonInterface
    {
        if (! $subscription) {
            return null;
        }

        if ($subscription->ends_at instanceof CarbonInterface) {
            return $subscription->ends_at;
        }

        if ($subscription->cycle_ends_on instanceof CarbonInterface) {
            return $subscription->cycle_ends_on->endOfDay();
        }

        if ($subscription->next_renewal_on instanceof CarbonInterface) {
            return $subscription->next_renewal_on->endOfDay();
        }

        return $subscription->starts_at instanceof CarbonInterface
            ? $subscription->starts_at->copy()->addMonth()->endOfDay()
            : null;
    }
}

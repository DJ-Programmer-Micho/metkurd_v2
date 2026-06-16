<?php

namespace App\Services\Plans;

use App\Models\Customer;
use App\Models\ServicePlan;
use App\Services\Billing\CustomerBillingStateService;
use Illuminate\Support\Facades\Cache;

class PlanConcurrencyService
{
    public const CACHE_KEY = 'service-plan-concurrency-limits:v1';

    public const DEFAULT_LIMIT = 1;

    /**
     * @var array{by_id: array<int, int>, by_code: array<string, int>}|null
     */
    protected static ?array $limitLookup = null;

    public function allowedConcurrentJobsForCustomer(?Customer $customer): int
    {
        if (! $customer instanceof Customer) {
            return self::DEFAULT_LIMIT;
        }

        $plan = method_exists($customer, 'currentServicePlan')
            ? $customer->currentServicePlan()
            : null;

        if (! $plan instanceof ServicePlan) {
            $plan = app(CustomerBillingStateService::class)->defaultServicePlan();
        }

        return $this->allowedConcurrentJobsForPlan($plan);
    }

    public function allowedConcurrentJobsForPlan(ServicePlan|int|string|null $plan): int
    {
        $lookup = $this->limitLookup();

        if ($plan instanceof ServicePlan) {
            $planId = (int) ($plan->getKey() ?? 0);
            $planCode = strtolower(trim((string) ($plan->code ?? '')));

            if ($planId > 0 && array_key_exists($planId, $lookup['by_id'])) {
                return $lookup['by_id'][$planId];
            }

            if ($planCode !== '' && array_key_exists($planCode, $lookup['by_code'])) {
                return $lookup['by_code'][$planCode];
            }

            return $this->normalizeLimit($plan->concurrent_jobs_limit ?? null);
        }

        if (is_int($plan) || ctype_digit((string) $plan)) {
            $planId = (int) $plan;

            return $lookup['by_id'][$planId] ?? self::DEFAULT_LIMIT;
        }

        $planCode = strtolower(trim((string) $plan));

        if ($planCode === '') {
            return self::DEFAULT_LIMIT;
        }

        return $lookup['by_code'][$planCode] ?? self::DEFAULT_LIMIT;
    }

    public function flushCache(): void
    {
        self::$limitLookup = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{by_id: array<int, int>, by_code: array<string, int>}
     */
    protected function limitLookup(): array
    {
        if (self::$limitLookup !== null) {
            return self::$limitLookup;
        }

        /** @var array{by_id: array<int, int>, by_code: array<string, int>} $lookup */
        $lookup = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $byId = [];
            $byCode = [];

            ServicePlan::query()
                ->select(['id', 'code', 'concurrent_jobs_limit'])
                ->get()
                ->each(function (ServicePlan $plan) use (&$byId, &$byCode): void {
                    $limit = $this->normalizeLimit($plan->concurrent_jobs_limit ?? null);
                    $planId = (int) ($plan->id ?? 0);
                    $planCode = strtolower(trim((string) ($plan->code ?? '')));

                    if ($planId > 0) {
                        $byId[$planId] = $limit;
                    }

                    if ($planCode !== '') {
                        $byCode[$planCode] = $limit;
                    }
                });

            return [
                'by_id' => $byId,
                'by_code' => $byCode,
            ];
        });

        return self::$limitLookup = $lookup;
    }

    protected function normalizeLimit(mixed $value): int
    {
        $limit = (int) $value;

        return $limit >= 1 ? $limit : self::DEFAULT_LIMIT;
    }
}

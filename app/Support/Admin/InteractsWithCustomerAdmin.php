<?php

namespace App\Support\Admin;

use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\Tool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;

trait InteractsWithCustomerAdmin
{
    #[Computed]
    public function customerPlanOptions()
    {
        return ServicePlan::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function customerToolOptions()
    {
        return Tool::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function customerCountryOptions()
    {
        return Customer::query()
            ->join('customer_profiles', 'customer_profiles.customer_id', '=', 'customers.id')
            ->whereNotNull('customer_profiles.country')
            ->where('customer_profiles.country', '!=', '')
            ->distinct()
            ->orderBy('customer_profiles.country')
            ->pluck('customer_profiles.country');
    }

    #[Computed]
    public function customerDirectoryOptions()
    {
        return Customer::query()
            ->orderBy('username')
            ->get(['id', 'username', 'email']);
    }

    protected function baseCustomerRelations(): array
    {
        return [
            'profile:id,customer_id,first_name,last_name,job_title,brand_name,country,city,address,zip_code,phone_number,avatar',
            'usage:id,customer_id,storage_used_bytes,jobs_total,jobs_succeeded,jobs_failed',
            'wallet:id,customer_id,balance_credits,subscription_balance_credits,addon_balance_credits,lifetime_earned,lifetime_spent,lifetime_refunded,cycle_started_on,cycle_ends_on,last_granted_at,last_charged_at',
            'servicePlan' => fn ($planQuery) => $planQuery->select('service_plans.id', 'service_plans.code', 'service_plans.name'),
            'activeServiceSubscription' => fn ($subscriptionQuery) => $subscriptionQuery->select(
                'customer_service_subscriptions.id',
                'customer_service_subscriptions.customer_id',
                'customer_service_subscriptions.service_plan_id',
                'customer_service_subscriptions.status',
                'customer_service_subscriptions.source',
                'customer_service_subscriptions.cycle_started_on',
                'customer_service_subscriptions.cycle_ends_on',
                'customer_service_subscriptions.next_renewal_on',
                'customer_service_subscriptions.auto_renew',
                'customer_service_subscriptions.starts_at',
                'customer_service_subscriptions.ends_at'
            ),
            'activeServiceSubscription.servicePlan' => fn ($planQuery) => $planQuery->select('service_plans.id', 'service_plans.code', 'service_plans.name'),
        ];
    }

    protected function customersOverviewQuery(?CarbonInterface $windowStart = null, string $jobStatus = 'all'): Builder
    {
        $query = Customer::query()
            ->with($this->baseCustomerRelations())
            ->withCount([
                'mlJobs as jobs_count' => fn (Builder $jobQuery) => $this->scopeJobs($jobQuery, $windowStart, $jobStatus),
                'mlJobs as done_jobs_count' => fn (Builder $jobQuery) => $this->scopeJobs($jobQuery, $windowStart, 'done'),
                'mlJobs as failed_jobs_count' => fn (Builder $jobQuery) => $this->scopeJobs($jobQuery, $windowStart, 'failed'),
            ])
            ->withSum([
                'mlJobs as consumed_credits' => fn (Builder $jobQuery) => $this->scopeJobs($jobQuery, $windowStart, $jobStatus),
            ], 'credits_charged')
            ->withMax([
                'mlJobs as last_job_at' => fn (Builder $jobQuery) => $this->scopeJobs($jobQuery, $windowStart, $jobStatus),
            ], 'created_at');

        return $this->withPurchaseAggregates($query, $windowStart);
    }

    protected function withPurchaseAggregates(Builder $query, ?CarbonInterface $windowStart = null): Builder
    {
        return $query
            ->withCount([
                'creditOrders as paid_orders_count' => fn (Builder $orderQuery) => $this->scopePaidOrders($orderQuery, $windowStart),
                'creditOrders as addon_orders_count' => fn (Builder $orderQuery) => $this->scopeAddonOrders($orderQuery, $windowStart),
                'creditOrders as subscription_orders_count' => fn (Builder $orderQuery) => $this->scopeSubscriptionOrders($orderQuery, $windowStart),
                'creditOrders as service_plan_orders_count' => fn (Builder $orderQuery) => $this->scopeServicePlanOrders($orderQuery, $windowStart),
                'creditOrders as storage_orders_count' => fn (Builder $orderQuery) => $this->scopeStorageOrders($orderQuery, $windowStart),
                'creditOrders as credit_product_orders_count' => fn (Builder $orderQuery) => $this->scopeCreditProductOrders($orderQuery, $windowStart),
            ])
            ->withSum([
                'creditOrders as paid_order_credits' => fn (Builder $orderQuery) => $this->scopePaidOrders($orderQuery, $windowStart),
            ], 'credits_amount')
            ->withSum([
                'creditOrders as paid_order_amount' => fn (Builder $orderQuery) => $this->scopePaidOrders($orderQuery, $windowStart),
            ], 'amount_usd')
            ->withSum([
                'creditOrders as addon_credits_bought' => fn (Builder $orderQuery) => $this->scopeAddonOrders($orderQuery, $windowStart),
            ], 'credits_amount')
            ->withSum([
                'creditOrders as addon_amount_spent' => fn (Builder $orderQuery) => $this->scopeAddonOrders($orderQuery, $windowStart),
            ], 'amount_usd')
            ->withSum([
                'creditOrders as subscription_credits_bought' => fn (Builder $orderQuery) => $this->scopeSubscriptionOrders($orderQuery, $windowStart),
            ], 'credits_amount')
            ->withSum([
                'creditOrders as subscription_amount_spent' => fn (Builder $orderQuery) => $this->scopeSubscriptionOrders($orderQuery, $windowStart),
            ], 'amount_usd')
            ->withSum([
                'creditOrders as service_plan_credits_bought' => fn (Builder $orderQuery) => $this->scopeServicePlanOrders($orderQuery, $windowStart),
            ], 'credits_amount')
            ->withSum([
                'creditOrders as service_plan_amount_spent' => fn (Builder $orderQuery) => $this->scopeServicePlanOrders($orderQuery, $windowStart),
            ], 'amount_usd')
            ->withSum([
                'creditOrders as storage_credits_bought' => fn (Builder $orderQuery) => $this->scopeStorageOrders($orderQuery, $windowStart),
            ], 'credits_amount')
            ->withSum([
                'creditOrders as storage_amount_spent' => fn (Builder $orderQuery) => $this->scopeStorageOrders($orderQuery, $windowStart),
            ], 'amount_usd')
            ->withSum([
                'creditOrders as credit_product_credits_bought' => fn (Builder $orderQuery) => $this->scopeCreditProductOrders($orderQuery, $windowStart),
            ], 'credits_amount')
            ->withSum([
                'creditOrders as credit_product_amount_spent' => fn (Builder $orderQuery) => $this->scopeCreditProductOrders($orderQuery, $windowStart),
            ], 'amount_usd')
            ->withMax([
                'creditOrders as last_paid_order_at' => fn (Builder $orderQuery) => $this->scopePaidOrders($orderQuery, $windowStart),
            ], 'created_at');
    }

    protected function scopeJobs(Builder|Relation $query, ?CarbonInterface $windowStart = null, string $jobStatus = 'all'): Builder|Relation
    {
        $query->where('status', '!=', 'deleted');

        if ($windowStart) {
            $query->where('created_at', '>=', $windowStart);
        }

        return match ($jobStatus) {
            'done' => $query->where('status', 'done'),
            'failed' => $query->where('status', 'failed'),
            'active' => $query->whereIn('status', ['queued', 'running', 'saving']),
            default => $query,
        };
    }

    protected function scopePaidOrders(Builder|Relation $query, ?CarbonInterface $windowStart = null): Builder|Relation
    {
        $query->where('status', 'paid');

        if ($windowStart) {
            $query->where('created_at', '>=', $windowStart);
        }

        return $query;
    }

    protected function scopeAddonOrders(Builder|Relation $query, ?CarbonInterface $windowStart = null): Builder|Relation
    {
        return $this->scopeCreditProductOrders($query, $windowStart);
    }

    protected function scopeCreditProductOrders(Builder|Relation $query, ?CarbonInterface $windowStart = null): Builder|Relation
    {
        return $this->scopePaidOrders($query, $windowStart)
            ->where(function (Builder $builder) {
                $builder
                    ->whereIn('order_type', ['addon', 'addon_purchase', 'credit'])
                    ->orWhereIn('source_type', ['credit_product', 'addon']);
            });
    }

    protected function scopeSubscriptionOrders(Builder|Relation $query, ?CarbonInterface $windowStart = null): Builder|Relation
    {
        return $this->scopeServicePlanOrders($query, $windowStart);
    }

    protected function scopeServicePlanOrders(Builder|Relation $query, ?CarbonInterface $windowStart = null): Builder|Relation
    {
        return $this->scopePaidOrders($query, $windowStart)
            ->where(function (Builder $builder) {
                $builder
                    ->where('order_type', 'subscription')
                    ->orWhere('source_type', 'service_plan');
            });
    }

    protected function scopeStorageOrders(Builder|Relation $query, ?CarbonInterface $windowStart = null): Builder|Relation
    {
        return $this->scopePaidOrders($query, $windowStart)
            ->where('source_type', 'storage_plan');
    }

    protected function applyCustomerSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($search) {
            $builder
                ->where('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('uid', 'like', "%{$search}%")
                ->orWhereHas('profile', function (Builder $profileQuery) use ($search) {
                    $profileQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('brand_name', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%");
                })
                ->orWhereHas('servicePlan', function (Builder $planQuery) use ($search) {
                    $planQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
        });
    }

    protected function applyPlanFilter(Builder $query, string $planFilter): Builder
    {
        if ($planFilter === 'all') {
            return $query;
        }

        if ($planFilter === 'none') {
            return $query->whereDoesntHave('servicePlan');
        }

        return $query->whereHas('servicePlan', fn (Builder $planQuery) => $planQuery->whereKey((int) $planFilter));
    }

    protected function applyCountryFilter(Builder $query, string $countryFilter): Builder
    {
        if ($countryFilter === 'all') {
            return $query;
        }

        return $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->where('country', $countryFilter));
    }

    protected function applyJoinedWindowFilter(Builder $query, string $joinedFilter): Builder
    {
        $windowStart = $this->windowStartFromFilter($joinedFilter);

        if (!$windowStart) {
            return $query;
        }

        return $query->where('created_at', '>=', $windowStart);
    }

    protected function applyCustomerStatusFilter(Builder $query, string $statusFilter): Builder
    {
        return match ($statusFilter) {
            'active' => $query->where('status', '!=', 0),
            'suspended' => $query->where('status', 0),
            default => $query,
        };
    }

    protected function windowStartFromFilter(string $periodFilter): ?CarbonInterface
    {
        return match ($periodFilter) {
            '7' => now()->subDays(7)->startOfDay(),
            '30' => now()->subDays(30)->startOfDay(),
            '90' => now()->subDays(90)->startOfDay(),
            '365' => now()->subDays(365)->startOfDay(),
            'all' => null,
            default => now()->subDays(30)->startOfDay(),
        };
    }

    public function periodLabel(string $periodFilter): string
    {
        return match ($periodFilter) {
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last 12 months',
            'all' => 'All time',
            default => 'Last 30 days',
        };
    }

    public function customerDisplayName($customer): string
    {
        $firstName = trim((string) data_get($customer, 'profile.first_name', ''));
        $lastName = trim((string) data_get($customer, 'profile.last_name', ''));
        $fullName = trim($firstName . ' ' . $lastName);

        return $fullName !== '' ? $fullName : (string) ($customer->username ?? 'Customer');
    }

    public function customerLocation($customer): string
    {
        $city = trim((string) data_get($customer, 'profile.city', ''));
        $country = trim((string) data_get($customer, 'profile.country', ''));
        $parts = array_values(array_filter([$city, $country], fn ($value) => $value !== ''));

        return $parts !== [] ? implode(', ', $parts) : 'Location not set';
    }

    public function customerStatusLabel($status): string
    {
        return (int) ($status ?? 1) === 0 ? 'Suspended' : 'Active';
    }

    public function customerStatusBadgeClasses($status): string
    {
        return (int) ($status ?? 1) === 0
            ? 'bg-danger-subtle text-danger'
            : 'bg-success-subtle text-success';
    }

    public function verificationBadgeClasses(bool $state): string
    {
        return $state ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
    }

    public function planBadgeClasses(?string $planCode): string
    {
        return $planCode
            ? 'bg-info-subtle text-info'
            : 'bg-light text-body';
    }

    public function paymentSourceLabel(?string $sourceType, ?string $orderType = null): string
    {
        return match (true) {
            $sourceType === 'storage_plan' => 'Storage Plan',
            $sourceType === 'service_plan', $orderType === 'subscription' => 'Service Plan',
            in_array($sourceType, ['credit_product', 'addon'], true), in_array($orderType, ['addon', 'addon_purchase', 'credit'], true) => 'Credit Product',
            default => ucwords(str_replace('_', ' ', (string) ($sourceType ?: $orderType ?: 'manual'))),
        };
    }

    public function formatCredits($value): string
    {
        return number_format((int) round((float) ($value ?? 0)));
    }

    public function formatMoney($value): string
    {
        return '$' . number_format((float) ($value ?? 0), 2);
    }

    public function formatBytes($bytes): string
    {
        $value = max(0, (int) ($bytes ?? 0));

        if ($value < 1024) {
            return $value . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $value / 1024;

        foreach ($units as $unit) {
            if ($size < 1024 || $unit === 'TB') {
                return number_format($size, $size >= 100 ? 0 : 1) . ' ' . $unit;
            }

            $size /= 1024;
        }

        return number_format($size, 1) . ' TB';
    }
}

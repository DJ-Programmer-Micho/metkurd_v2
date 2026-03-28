<?php

namespace App\Support\Admin;

use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\MlJob;
use App\Models\ServicePlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesAdminHomePage
{
    #[Url(as: 'period', keep: true)]
    public string $periodFilter = '30';

    protected function analyticsWindowStart(): ?CarbonInterface
    {
        return match ($this->periodFilter) {
            '7' => now()->subDays(7)->startOfDay(),
            '30' => now()->subDays(30)->startOfDay(),
            '90' => now()->subDays(90)->startOfDay(),
            '365' => now()->subDays(365)->startOfDay(),
            'all' => null,
            default => now()->subDays(30)->startOfDay(),
        };
    }

    protected function analyticsCacheKey(string $section): string
    {
        return 'admin-dashboard:' . $section . ':' . $this->periodFilter;
    }

    protected function analyticsCacheTtl(): CarbonInterface
    {
        return now()->addMinutes(5);
    }

    protected function paymentSourceKeyExpression(): string
    {
        return "CASE
            WHEN source_type = 'storage_plan' THEN 'storage_plan'
            WHEN order_type = 'subscription' OR source_type = 'service_plan' THEN 'service_plan'
            WHEN order_type IN ('addon','addon_purchase','credit') OR source_type IN ('credit_product','addon') THEN 'credit_product'
            ELSE 'other'
        END";
    }

    public function paymentSourceLabel(string $key): string
    {
        return match ($key) {
            'service_plan' => __('Service Plans'),
            'storage_plan' => __('Storage Plans'),
            'credit_product' => __('Credit Products'),
            default => __('Other Orders'),
        };
    }

    protected function paymentSourceSummary(?CarbonInterface $windowStart = null)
    {
        $classifiedOrders = DB::table('credit_orders')
            ->where('status', 'paid')
            ->when($windowStart, fn ($query) => $query->where('created_at', '>=', $windowStart))
            ->select('customer_id', 'amount_usd', 'credits_amount')
            ->selectRaw($this->paymentSourceKeyExpression() . ' as source_key');

        return DB::query()
            ->fromSub($classifiedOrders, 'classified_orders')
            ->select('source_key')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COUNT(DISTINCT customer_id) as customers')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
            ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits')
            ->groupBy('source_key')
            ->orderByRaw("CASE source_key
                WHEN 'service_plan' THEN 0
                WHEN 'storage_plan' THEN 1
                WHEN 'credit_product' THEN 2
                ELSE 3
            END")
            ->get()
            ->map(function ($row) {
                $row->label = $this->paymentSourceLabel((string) $row->source_key);

                return $row;
            })
            ->keyBy('source_key');
    }

    #[Computed]
    public function overviewStats(): array
    {
        return Cache::remember($this->analyticsCacheKey('overview'), $this->analyticsCacheTtl(), function () {
            $windowStart = $this->analyticsWindowStart();

            $customerSummary = Customer::query()
                ->selectRaw('COUNT(*) as total_customers')
                ->selectRaw('SUM(CASE WHEN status != 0 THEN 1 ELSE 0 END) as active_customers')
                ->selectRaw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as suspended_customers');

            if ($windowStart) {
                $customerSummary->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as period_new_customers', [$windowStart]);
            } else {
                $customerSummary->selectRaw('COUNT(*) as period_new_customers');
            }

            $customerSummary = $customerSummary->first();

            $subscriptionSummary = CustomerServiceSubscription::query()
                ->join('service_plans', 'service_plans.id', '=', 'customer_service_subscriptions.service_plan_id')
                ->where('customer_service_subscriptions.status', 'active')
                ->selectRaw('COUNT(*) as active_subscriptions')
                ->selectRaw('SUM(CASE WHEN service_plans.is_free = 0 THEN 1 ELSE 0 END) as paid_subscribers')
                ->selectRaw('SUM(CASE WHEN service_plans.is_free = 1 THEN 1 ELSE 0 END) as free_subscribers')
                ->first();

            $orderSummary = CreditOrder::query()
                ->where('status', 'paid')
                ->selectRaw('COUNT(*) as paid_orders')
                ->selectRaw('COUNT(DISTINCT customer_id) as purchasing_customers')
                ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue_total')
                ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits_sold_total');

            if ($windowStart) {
                $orderSummary
                    ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as period_orders', [$windowStart])
                    ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN amount_usd ELSE 0 END), 0) as revenue_period', [$windowStart])
                    ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN credits_amount ELSE 0 END), 0) as credits_sold_period', [$windowStart]);
            } else {
                $orderSummary
                    ->selectRaw('COUNT(*) as period_orders')
                    ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue_period')
                    ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits_sold_period');
            }

            $orderSummary = $orderSummary->first();
            $allTimePaymentSources = $this->paymentSourceSummary();
            $periodPaymentSources = $windowStart ? $this->paymentSourceSummary($windowStart) : $allTimePaymentSources;

            $jobSummary = MlJob::query()
                ->where('status', '!=', 'deleted')
                ->selectRaw('COUNT(*) as jobs_total')
                ->selectRaw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_jobs_total")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs_total")
                ->selectRaw("SUM(CASE WHEN status IN ('queued','running','saving') THEN 1 ELSE 0 END) as active_jobs")
                ->selectRaw('COALESCE(SUM(credits_charged), 0) as consumed_total');

            if ($windowStart) {
                $jobSummary
                    ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as jobs_period', [$windowStart])
                    ->selectRaw("SUM(CASE WHEN created_at >= ? AND status = 'done' THEN 1 ELSE 0 END) as done_jobs_period", [$windowStart])
                    ->selectRaw("SUM(CASE WHEN created_at >= ? AND status = 'failed' THEN 1 ELSE 0 END) as failed_jobs_period", [$windowStart])
                    ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN credits_charged ELSE 0 END), 0) as consumed_period', [$windowStart]);
            } else {
                $jobSummary
                    ->selectRaw('COUNT(*) as jobs_period')
                    ->selectRaw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_jobs_period")
                    ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs_period")
                    ->selectRaw('COALESCE(SUM(credits_charged), 0) as consumed_period');
            }

            $jobSummary = $jobSummary->first();

            $periodTerminalJobs = (int) (($jobSummary->done_jobs_period ?? 0) + ($jobSummary->failed_jobs_period ?? 0));
            $successRate = $periodTerminalJobs > 0
                ? ((int) ($jobSummary->done_jobs_period ?? 0) / $periodTerminalJobs) * 100
                : 0;

            $activeCustomers = max(1, (int) ($customerSummary->active_customers ?? 0));
            $paymentSourceStats = collect(['service_plan', 'storage_plan', 'credit_product'])->mapWithKeys(function (string $key) use ($allTimePaymentSources, $periodPaymentSources) {
                $totalRow = $allTimePaymentSources->get($key);
                $periodRow = $periodPaymentSources->get($key);

                return [
                    $key => [
                        'label' => $this->paymentSourceLabel($key),
                        'orders_total' => (int) ($totalRow->orders ?? 0),
                        'orders_period' => (int) ($periodRow->orders ?? 0),
                        'revenue_total' => (float) ($totalRow->revenue ?? 0),
                        'revenue_period' => (float) ($periodRow->revenue ?? 0),
                        'credits_total' => (int) ($totalRow->credits ?? 0),
                        'credits_period' => (int) ($periodRow->credits ?? 0),
                    ],
                ];
            })->all();

            return [
                'customers_total' => (int) ($customerSummary->total_customers ?? 0),
                'active_customers' => (int) ($customerSummary->active_customers ?? 0),
                'suspended_customers' => (int) ($customerSummary->suspended_customers ?? 0),
                'period_new_customers' => (int) ($customerSummary->period_new_customers ?? 0),
                'paid_subscribers' => (int) ($subscriptionSummary->paid_subscribers ?? 0),
                'free_subscribers' => (int) ($subscriptionSummary->free_subscribers ?? 0),
                'paid_subscriber_share' => ((int) ($subscriptionSummary->paid_subscribers ?? 0) / $activeCustomers) * 100,
                'revenue_total' => (float) ($orderSummary->revenue_total ?? 0),
                'revenue_period' => (float) ($orderSummary->revenue_period ?? 0),
                'credits_sold_total' => (int) ($orderSummary->credits_sold_total ?? 0),
                'credits_sold_period' => (int) ($orderSummary->credits_sold_period ?? 0),
                'consumed_total' => (int) ($jobSummary->consumed_total ?? 0),
                'consumed_period' => (int) ($jobSummary->consumed_period ?? 0),
                'jobs_total' => (int) ($jobSummary->jobs_total ?? 0),
                'jobs_period' => (int) ($jobSummary->jobs_period ?? 0),
                'active_jobs' => (int) ($jobSummary->active_jobs ?? 0),
                'success_rate' => $successRate,
                'period_orders' => (int) ($orderSummary->period_orders ?? 0),
                'purchasing_customers' => (int) ($orderSummary->purchasing_customers ?? 0),
                'revenue_sources' => $paymentSourceStats,
            ];
        });
    }

    #[Computed]
    public function planMix()
    {
        return Cache::remember($this->analyticsCacheKey('plan-mix'), $this->analyticsCacheTtl(), function () {
            $windowStart = $this->analyticsWindowStart();

            $activeSubscribers = CustomerServiceSubscription::query()
                ->where('status', 'active')
                ->groupBy('service_plan_id')
                ->selectRaw('service_plan_id')
                ->selectRaw('COUNT(*) as active_subscribers');

            $planRevenue = CreditOrder::query()
                ->where('status', 'paid')
                ->whereNotNull('service_plan_id')
                ->when($windowStart, fn ($query) => $query->where('created_at', '>=', $windowStart))
                ->groupBy('service_plan_id')
                ->selectRaw('service_plan_id')
                ->selectRaw('COUNT(*) as paid_orders')
                ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
                ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits_sold');

            return ServicePlan::query()
                ->leftJoinSub($activeSubscribers, 'plan_active_subscribers', fn ($join) => $join->on('plan_active_subscribers.service_plan_id', '=', 'service_plans.id'))
                ->leftJoinSub($planRevenue, 'plan_revenue', fn ($join) => $join->on('plan_revenue.service_plan_id', '=', 'service_plans.id'))
                ->select('service_plans.id', 'service_plans.code', 'service_plans.name', 'service_plans.is_free', 'service_plans.monthly_credits', 'service_plans.price_usd_monthly', 'service_plans.sort_order')
                ->selectRaw('COALESCE(plan_active_subscribers.active_subscribers, 0) as active_subscribers')
                ->selectRaw('COALESCE(plan_revenue.paid_orders, 0) as paid_orders')
                ->selectRaw('COALESCE(plan_revenue.revenue, 0) as revenue')
                ->selectRaw('COALESCE(plan_revenue.credits_sold, 0) as credits_sold')
                ->orderByDesc('active_subscribers')
                ->orderBy('service_plans.sort_order')
                ->orderBy('service_plans.name')
                ->get();
        });
    }

    #[Computed]
    public function purchaseMix()
    {
        return Cache::remember($this->analyticsCacheKey('purchase-mix'), $this->analyticsCacheTtl(), function () {
            $windowStart = $this->analyticsWindowStart();

            return $this->paymentSourceSummary($windowStart)
                ->values();
        });
    }

    #[Computed]
    public function topTools()
    {
        return Cache::remember($this->analyticsCacheKey('tool-mix'), $this->analyticsCacheTtl(), function () {
            $windowStart = $this->analyticsWindowStart();
            $toolNameExpression = "COALESCE(tools.name, action_tools.name, ml_jobs.job_kind, 'Unknown Tool')";
            $toolCodeExpression = "COALESCE(tools.code, action_tools.code, tool_actions.tool_code, ml_jobs.job_kind, 'unknown')";

            return DB::table('ml_jobs')
                ->leftJoin('tool_actions', 'tool_actions.id', '=', 'ml_jobs.tool_action_id')
                ->leftJoin('tools', 'tools.id', '=', 'ml_jobs.tool_id')
                ->leftJoin('tools as action_tools', 'action_tools.code', '=', 'tool_actions.tool_code')
                ->where('ml_jobs.status', '!=', 'deleted')
                ->when($windowStart, fn ($query) => $query->where('ml_jobs.created_at', '>=', $windowStart))
                ->selectRaw($toolNameExpression . ' as tool_name')
                ->selectRaw($toolCodeExpression . ' as tool_code')
                ->selectRaw('COUNT(*) as jobs')
                ->selectRaw('COUNT(DISTINCT ml_jobs.customer_id) as customers')
                ->selectRaw("SUM(CASE WHEN ml_jobs.status = 'done' THEN 1 ELSE 0 END) as completed_jobs")
                ->selectRaw("SUM(CASE WHEN ml_jobs.status = 'failed' THEN 1 ELSE 0 END) as failed_jobs")
                ->selectRaw('COALESCE(SUM(ml_jobs.credits_charged), 0) as credits')
                ->groupBy(DB::raw($toolNameExpression))
                ->groupBy(DB::raw($toolCodeExpression))
                ->orderByDesc('credits')
                ->orderByDesc('jobs')
                ->limit(8)
                ->get();
        });
    }

    #[Computed]
    public function topCountries()
    {
        return Cache::remember($this->analyticsCacheKey('country-mix'), $this->analyticsCacheTtl(), function () {
            $windowStart = $this->analyticsWindowStart();

            $customerRevenue = CreditOrder::query()
                ->where('status', 'paid')
                ->when($windowStart, fn ($query) => $query->where('created_at', '>=', $windowStart))
                ->groupBy('customer_id')
                ->selectRaw('customer_id')
                ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue');

            return DB::table('customers')
                ->join('customer_profiles', 'customer_profiles.customer_id', '=', 'customers.id')
                ->leftJoinSub($customerRevenue, 'country_revenue', fn ($join) => $join->on('country_revenue.customer_id', '=', 'customers.id'))
                ->whereNotNull('customer_profiles.country')
                ->where('customer_profiles.country', '!=', '')
                ->selectRaw('customer_profiles.country as country')
                ->selectRaw('COUNT(*) as customers')
                ->selectRaw('SUM(CASE WHEN customers.status != 0 THEN 1 ELSE 0 END) as active_customers')
                ->selectRaw('COALESCE(SUM(country_revenue.revenue), 0) as revenue')
                ->groupBy('customer_profiles.country')
                ->orderByDesc('customers')
                ->limit(8)
                ->get();
        });
    }

    #[Computed]
    public function recentActivity(): array
    {
        return Cache::remember($this->analyticsCacheKey('recent-activity'), $this->analyticsCacheTtl(), function () {
            $timelineStart = now()->subDays(13)->startOfDay();
            $dateExpression = DB::raw('DATE(created_at)');

            $customerRows = DB::table('customers')
                ->where('created_at', '>=', $timelineStart)
                ->selectRaw('DATE(created_at) as day')
                ->selectRaw('COUNT(*) as customers')
                ->groupBy($dateExpression)
                ->pluck('customers', 'day');

            $orderRows = DB::table('credit_orders')
                ->where('status', 'paid')
                ->where('created_at', '>=', $timelineStart)
                ->selectRaw('DATE(created_at) as day')
                ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
                ->groupBy($dateExpression)
                ->pluck('revenue', 'day');

            $jobRows = DB::table('ml_jobs')
                ->where('status', '!=', 'deleted')
                ->where('created_at', '>=', $timelineStart)
                ->selectRaw('DATE(created_at) as day')
                ->selectRaw('COUNT(*) as jobs')
                ->selectRaw('COALESCE(SUM(credits_charged), 0) as credits')
                ->groupBy($dateExpression)
                ->get()
                ->keyBy('day');

            $rows = [];
            $maxCredits = 1;

            for ($offset = 13; $offset >= 0; $offset--) {
                $day = now()->subDays($offset)->toDateString();
                $jobRow = $jobRows->get($day);
                $credits = (int) ($jobRow->credits ?? 0);
                $maxCredits = max($maxCredits, $credits);

                $rows[] = [
                    'day' => $day,
                    'label' => now()->subDays($offset)->format('M d'),
                    'customers' => (int) ($customerRows[$day] ?? 0),
                    'revenue' => (float) ($orderRows[$day] ?? 0),
                    'jobs' => (int) ($jobRow->jobs ?? 0),
                    'credits' => $credits,
                ];
            }

            return [
                'rows' => $rows,
                'max_credits' => $maxCredits,
            ];
        });
    }

    #[Computed]
    public function chartPayload(): array
    {
        $activityRows = collect($this->recentActivity['rows'] ?? []);
        $purchaseMix = $this->purchaseMix;
        $topTools = $this->topTools;
        $planMix = $this->planMix;

        return [
            'activity' => [
                'labels' => $activityRows->pluck('label')->values()->all(),
                'jobs' => $activityRows->pluck('jobs')->map(fn ($value) => (int) $value)->values()->all(),
                'customers' => $activityRows->pluck('customers')->map(fn ($value) => (int) $value)->values()->all(),
                'revenue' => $activityRows->pluck('revenue')->map(fn ($value) => (float) $value)->values()->all(),
            ],
            'purchase_mix' => [
                'labels' => $purchaseMix->map(fn ($row) => (string) __((string) ($row->label ?? $row->category ?? 'Other Orders')))->values()->all(),
                'revenue' => $purchaseMix->map(fn ($row) => (float) ($row->revenue ?? 0))->values()->all(),
                'orders' => $purchaseMix->map(fn ($row) => (int) ($row->orders ?? 0))->values()->all(),
                'credits' => $purchaseMix->map(fn ($row) => (int) ($row->credits ?? 0))->values()->all(),
            ],
            'top_tools' => [
                'labels' => $topTools->map(fn ($row) => (string) __((string) ($row->tool_name ?? 'Unknown Tool')))->values()->all(),
                'codes' => $topTools->map(fn ($row) => (string) ($row->tool_code ?? 'unknown'))->values()->all(),
                'credits' => $topTools->map(fn ($row) => (int) ($row->credits ?? 0))->values()->all(),
                'jobs' => $topTools->map(fn ($row) => (int) ($row->jobs ?? 0))->values()->all(),
            ],
            'plan_mix' => [
                'labels' => $planMix->map(fn ($row) => (string) __((string) ($row->name ?? 'Unknown Plan')))->values()->all(),
                'codes' => $planMix->map(fn ($row) => (string) ($row->code ?? 'unknown'))->values()->all(),
                'subscribers' => $planMix->map(fn ($row) => (int) ($row->active_subscribers ?? 0))->values()->all(),
                'revenue' => $planMix->map(fn ($row) => (float) ($row->revenue ?? 0))->values()->all(),
            ],
        ];
    }

    public function periodLabel(string $periodFilter): string
    {
        return match ($periodFilter) {
            '7' => __('Last 7 days'),
            '30' => __('Last 30 days'),
            '90' => __('Last 90 days'),
            '365' => __('Last 12 months'),
            'all' => __('All time'),
            default => __('Last 30 days'),
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

    public function formatPercent($value, int $precision = 1): string
    {
        return number_format((float) ($value ?? 0), $precision) . '%';
    }

    public function trendWidth($value, $max): string
    {
        $width = $max > 0 ? min(100, ((float) $value / (float) $max) * 100) : 0;

        return number_format($width, 2, '.', '');
    }
}

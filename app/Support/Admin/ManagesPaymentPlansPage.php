<?php

namespace App\Support\Admin;

use App\Domain\Payments\Enums\PaymentMode;
use App\Models\CreditOrder;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Services\CustomerApi\CustomerApiAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesPaymentPlansPage
{
    use InteractsWithPaymentAdmin;
    use SecureAdminComponent;
    use ShowsV2Catalog;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'type', keep: true)]
    public string $typeFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'sort_order';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'asc';

    public int $perPage = 10;

    public ?int $editingPlanId = null;

    public string $code = '';

    public string $name = '';

    public array $billingIntervals = ['monthly'];

    public string $paymentMode = 'recurring';

    public $monthlyCredits = '';

    public $appMonthlyCredits = '';

    public $apiMonthlyCredits = 0;

    public $concurrentJobsLimit = 2;

    public bool $apiEnabled = false;

    public $apiRequestsPerMinute = 0;

    public $apiConcurrentJobs = 0;

    public string $apiAllowedToolsText = '';

    public $priceIqdMonthly = '';

    public $priceIqdYearly = '';

    public bool $isFree = false;

    public bool $isActive = true;

    public $sortOrder = 0;

    public string $uiFeaturesJson = '';

    public string $metaJson = '';

    public ?int $deletePlanId = null;

    public string $deletePlanLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMonthlyCredits($value): void
    {
        $this->appMonthlyCredits = $value;
    }

    public function updatedAppMonthlyCredits($value): void
    {
        $this->monthlyCredits = $value;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->typeFilter = 'all';
        $this->sortColumn = 'sort_order';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['sort_order', 'name', 'monthly_credits', 'price_iqd_monthly', 'active_subscribers', 'paid_orders', 'revenue'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['monthly_credits', 'price_iqd_monthly', 'active_subscribers', 'paid_orders', 'revenue'], true)
            ? 'desc'
            : 'asc';
    }

    protected function planFormRules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash|unique:service_plans,code,'.($this->editingPlanId ?? 'NULL').',id',
            'name' => 'required|string|max:120',
            'billingIntervals' => 'required|array|min:1',
            'billingIntervals.*' => 'required|string|in:monthly,yearly,lifetime',
            'paymentMode' => 'required|string|in:one_time,recurring',
            'monthlyCredits' => 'required|integer|min:0',
            'appMonthlyCredits' => 'required|integer|min:0',
            'apiMonthlyCredits' => 'required|integer|min:0',
            'concurrentJobsLimit' => 'required|integer|min:1|max:65535',
            'apiEnabled' => 'boolean',
            'apiRequestsPerMinute' => 'required|integer|min:0|max:65535',
            'apiConcurrentJobs' => 'required|integer|min:0|max:65535',
            'apiAllowedToolsText' => 'nullable|string',
            'priceIqdMonthly' => 'nullable|integer|min:0',
            'priceIqdYearly' => 'nullable|integer|min:0',
            'sortOrder' => 'nullable|integer|min:0|max:65535',
            'uiFeaturesJson' => 'nullable|string',
            'metaJson' => 'nullable|string',
        ];
    }

    #[Computed]
    public function topStats(): array
    {
        $canonicalAmountSql = $this->canonicalAmountSql('credit_orders');
        $activeSubscribers = (int) CustomerServiceSubscription::query()
            ->where('status', 'active')
            ->count();

        $orderSummary = CreditOrder::query()
            ->revenueIncluded()
            ->where('status', 'paid')
            ->whereNotNull('service_plan_id')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw("COALESCE(SUM({$canonicalAmountSql}), 0) as revenue")
            ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits')
            ->first();

        return [
            'plans' => (int) ServicePlan::query()->count(),
            'active_plans' => (int) ServicePlan::query()->where('is_active', true)->count(),
            'paid_plans' => (int) ServicePlan::query()->where('is_free', false)->count(),
            'active_subscribers' => $activeSubscribers,
            'orders' => (int) ($orderSummary->orders ?? 0),
            'revenue' => (float) ($orderSummary->revenue ?? 0),
            'credits' => (int) ($orderSummary->credits ?? 0),
        ];
    }

    protected function plansBaseQuery(): Builder
    {
        $canonicalAmountSql = $this->canonicalAmountSql('credit_orders');
        $priceIqdMonthlySql = $this->effectiveCatalogAmountSql('service_plans', 'price_iqd_monthly', 'price_usd_monthly');
        $priceIqdYearlySql = $this->effectiveCatalogAmountSql('service_plans', 'price_iqd_yearly', 'price_usd_yearly');
        $activeSubscribers = CustomerServiceSubscription::query()
            ->where('status', 'active')
            ->groupBy('service_plan_id')
            ->selectRaw('service_plan_id')
            ->selectRaw('COUNT(*) as active_subscribers');

        $planRevenue = CreditOrder::query()
            ->revenueIncluded()
            ->where('status', 'paid')
            ->whereNotNull('service_plan_id')
            ->groupBy('service_plan_id')
            ->selectRaw('service_plan_id')
            ->selectRaw('COUNT(*) as paid_orders')
            ->selectRaw("COALESCE(SUM({$canonicalAmountSql}), 0) as revenue")
            ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits_sold')
            ->selectRaw('MAX(created_at) as last_order_at');

        $query = ServicePlan::query()
            ->leftJoinSub($activeSubscribers, 'plan_active_subscribers', fn ($join) => $join->on('plan_active_subscribers.service_plan_id', '=', 'service_plans.id'))
            ->leftJoinSub($planRevenue, 'plan_revenue', fn ($join) => $join->on('plan_revenue.service_plan_id', '=', 'service_plans.id'))
            ->select('service_plans.*')
            ->selectRaw('COALESCE(service_plans.app_monthly_credits, service_plans.monthly_credits, 0) as app_monthly_credits_effective')
            ->selectRaw('COALESCE(service_plans.api_monthly_credits, 0) as api_monthly_credits_effective')
            ->selectRaw("{$priceIqdMonthlySql} as price_iqd_monthly_effective")
            ->selectRaw("{$priceIqdYearlySql} as price_iqd_yearly_effective")
            ->selectRaw('COALESCE(plan_active_subscribers.active_subscribers, 0) as active_subscribers')
            ->selectRaw('COALESCE(plan_revenue.paid_orders, 0) as paid_orders')
            ->selectRaw('COALESCE(plan_revenue.revenue, 0) as revenue')
            ->selectRaw('COALESCE(plan_revenue.credits_sold, 0) as credits_sold')
            ->selectRaw('plan_revenue.last_order_at as last_order_at');

        $search = trim($this->search);
        $hasPaymentModeColumn = $this->tableHasColumn('service_plans', 'payment_mode');
        $hasBillingIntervalsColumn = $this->tableHasColumn('service_plans', 'billing_intervals');
        $normalizedSearch = strtolower($search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $hasPaymentModeColumn, $hasBillingIntervalsColumn, $normalizedSearch) {
                $builder
                    ->where('service_plans.name', 'like', "%{$search}%")
                    ->orWhere('service_plans.code', 'like', "%{$search}%")
                    ->orWhere('service_plans.billing_interval', 'like', "%{$search}%");

                if ($hasPaymentModeColumn) {
                    $builder->orWhere('service_plans.payment_mode', 'like', "%{$search}%");
                }

                if ($hasBillingIntervalsColumn && in_array($normalizedSearch, ['monthly', 'yearly', 'lifetime'], true)) {
                    $builder->orWhereJsonContains('service_plans.billing_intervals', $normalizedSearch);
                }
            });
        }

        if ($this->statusFilter === 'active') {
            $query->where('service_plans.is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('service_plans.is_active', false);
        }

        if ($this->typeFilter === 'free') {
            $query->where('service_plans.is_free', true);
        } elseif ($this->typeFilter === 'paid') {
            $query->where('service_plans.is_free', false);
        }

        $column = match ($this->sortColumn) {
            'name' => 'service_plans.name',
            'monthly_credits' => 'app_monthly_credits_effective',
            'price_iqd_monthly' => 'price_iqd_monthly_effective',
            'active_subscribers' => 'active_subscribers',
            'paid_orders' => 'paid_orders',
            'revenue' => 'revenue',
            default => 'service_plans.sort_order',
        };

        return $query
            ->orderBy($column, $this->sortDirection)
            ->orderBy('service_plans.name');
    }

    #[Computed]
    public function plans()
    {
        return $this->plansBaseQuery()->paginate($this->perPage);
    }

    public function openCreatePlanModal(): void
    {
        $this->resetPlanForm();
        $this->dispatch('payments-plans:modal-show', id: 'paymentPlanModal');
    }

    public function openEditPlanModal(int $planId): void
    {
        $plan = ServicePlan::query()->findOrFail($planId);
        $resolvedAppMonthlyCredits = (int) ($plan->app_monthly_credits ?? $plan->monthly_credits ?? 0);

        $this->editingPlanId = $plan->id;
        $this->code = (string) $plan->code;
        $this->name = (string) $plan->name;
        $this->billingIntervals = $plan->billingIntervals();
        $this->paymentMode = $plan->checkoutPaymentModeValue();
        $this->monthlyCredits = $resolvedAppMonthlyCredits;
        $this->appMonthlyCredits = $resolvedAppMonthlyCredits;
        $this->apiMonthlyCredits = (int) ($plan->api_monthly_credits ?? 0);
        $this->concurrentJobsLimit = (int) ($plan->concurrent_jobs_limit ?? 2);
        $this->apiEnabled = (bool) ($plan->api_enabled ?? false);
        $this->apiRequestsPerMinute = (int) ($plan->api_requests_per_minute ?? 0);
        $this->apiConcurrentJobs = (int) ($plan->api_concurrent_jobs ?? 0);
        $this->apiAllowedToolsText = implode(PHP_EOL, app(\App\Services\Admin\AdminEntitlementScopes::class)->explicit($plan));
        $this->priceIqdMonthly = (string) ((int) $plan->priceIqdForCycle('monthly'));
        $this->priceIqdYearly = (string) ((int) $plan->priceIqdForCycle('yearly'));
        $this->isFree = (bool) $plan->is_free;
        $this->isActive = (bool) $plan->is_active;
        $this->sortOrder = (int) ($plan->sort_order ?? 0);
        $this->uiFeaturesJson = $this->encodeJsonTextarea($plan->ui_features);
        $this->metaJson = $this->encodeJsonTextarea(\Illuminate\Support\Arr::except($plan->meta ?? [], [\App\Services\Admin\AdminEntitlementScopes::META_KEY]));
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-plans:modal-show', id: 'paymentPlanModal');
    }

    public function savePlan(): void
    {
        $this->authorizeAdminChange('admin.pricing');

        if (($this->appMonthlyCredits === '' || $this->appMonthlyCredits === null) && $this->monthlyCredits !== '') {
            $this->appMonthlyCredits = $this->monthlyCredits;
        }

        if (($this->monthlyCredits === '' || $this->monthlyCredits === null) && $this->appMonthlyCredits !== '') {
            $this->monthlyCredits = $this->appMonthlyCredits;
        }

        $validated = $this->validate($this->planFormRules());
        $billingIntervals = $this->normalizePlanBillingIntervals($validated['billingIntervals'] ?? []);
        $primaryBillingInterval = $this->primaryPlanBillingInterval($billingIntervals);
        $uiFeatures = $this->decodeJsonTextarea($validated['uiFeaturesJson'] ?? '', 'uiFeaturesJson');
        $meta = $this->decodeJsonTextarea($validated['metaJson'] ?? '', 'metaJson');
        $apiAllowedTools = $this->normalizeApiAllowedToolsText($validated['apiAllowedToolsText'] ?? '');
        $priceIqdMonthly = (int) (($validated['priceIqdMonthly'] !== '' && $validated['priceIqdMonthly'] !== null) ? $validated['priceIqdMonthly'] : 0);
        $priceIqdYearly = (int) (($validated['priceIqdYearly'] !== '' && $validated['priceIqdYearly'] !== null) ? $validated['priceIqdYearly'] : 0);

        [$plan, $originalMode] = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $billingIntervals, $primaryBillingInterval, $uiFeatures, $meta, $apiAllowedTools, $priceIqdMonthly, $priceIqdYearly) {
            $plan = $this->editingPlanId
                ? ServicePlan::query()->lockForUpdate()->findOrFail($this->editingPlanId)
                : new ServicePlan;
            $originalMode = $plan->checkoutPaymentMode();
            unset($meta[\App\Services\Admin\AdminEntitlementScopes::META_KEY]);
            $meta[\App\Services\Admin\AdminEntitlementScopes::META_KEY] = data_get($plan->meta, \App\Services\Admin\AdminEntitlementScopes::META_KEY, []);
            $appMonthlyCredits = (int) ($validated['appMonthlyCredits'] ?? $validated['monthlyCredits']);
            $payload = [
                'code' => $validated['code'],
                'name' => $validated['name'],
                'billing_interval' => $primaryBillingInterval,
                'monthly_credits' => $appMonthlyCredits,
                'app_monthly_credits' => $appMonthlyCredits,
                'api_monthly_credits' => (int) $validated['apiMonthlyCredits'],
                'concurrent_jobs_limit' => (int) $validated['concurrentJobsLimit'],
                'api_enabled' => (bool) $validated['apiEnabled'],
                'api_requests_per_minute' => (int) $validated['apiRequestsPerMinute'],
                'api_concurrent_jobs' => (int) $validated['apiConcurrentJobs'],
                'api_allowed_tools' => $apiAllowedTools,
                'price_usd_monthly' => $this->usdReferenceAmount($priceIqdMonthly),
                'price_usd_yearly' => $this->usdReferenceAmount($priceIqdYearly),
                'is_free' => (bool) $this->isFree,
                'is_active' => (bool) $this->isActive,
                'sort_order' => (int) ($validated['sortOrder'] ?? 0),
                'ui_features' => $uiFeatures,
                'meta' => $meta,
            ];

            if ($this->tableHasColumn('service_plans', 'payment_mode')) {
                $payload['payment_mode'] = PaymentMode::fromValue(
                    $validated['paymentMode'] ?? null,
                    PaymentMode::RECURRING
                )->value;
            }

            if ($this->tableHasColumn('service_plans', 'billing_intervals')) {
                $payload['billing_intervals'] = $billingIntervals;
            }

            if ($this->tableHasColumn('service_plans', 'price_iqd_monthly')) {
                $payload['price_iqd_monthly'] = $priceIqdMonthly;
            }

            if ($this->tableHasColumn('service_plans', 'price_iqd_yearly')) {
                $payload['price_iqd_yearly'] = $priceIqdYearly;
            }

            $plan->fill($payload);

            if ($plan->is_free) {
                if ($this->tableHasColumn('service_plans', 'price_iqd_monthly')) {
                    $plan->price_iqd_monthly = 0;
                }

                if ($this->tableHasColumn('service_plans', 'price_iqd_yearly')) {
                    $plan->price_iqd_yearly = 0;
                }

                $plan->price_usd_monthly = 0;
                $plan->price_usd_yearly = 0;
            }

            $plan->save();
            app(\App\Services\Admin\AdminEntitlementScopes::class)->synchronize($plan, $apiAllowedTools);

            return [$plan, $originalMode];
        });
        unset($this->plans, $this->topStats);
        $updatedMode = $plan->checkoutPaymentMode();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingPlanId ? __('Service plan updated successfully.') : __('Service plan created successfully.')
        );

        if ($this->editingPlanId && $originalMode !== $updatedMode) {
            Log::warning('Service plan payment mode changed. Existing subscriptions are not modified; only future checkouts use the new mode.', [
                'service_plan_id' => (int) $plan->id,
                'service_plan_code' => (string) $plan->code,
                'previous_mode' => $originalMode->value,
                'new_mode' => $updatedMode->value,
            ]);

            $this->dispatch(
                'alert',
                type: 'warning',
                message: __('Payment mode changes affect only future purchases. Existing subscriptions and payment records stay unchanged.')
            );
        }

        $this->resetPlanForm();
        $this->dispatch('payments-plans:modal-hide', id: 'paymentPlanModal');
    }

    public function togglePlanStatus(int $planId): void
    {
        $this->authorizeAdminChange('admin.pricing');

        $plan = ServicePlan::query()->findOrFail($planId);
        $plan->update(['is_active' => ! $plan->is_active]);
        unset($this->plans, $this->topStats);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $plan->is_active ? __('Plan activated successfully.') : __('Plan deactivated successfully.')
        );
    }

    public function confirmDeletePlan(int $planId): void
    {
        $plan = ServicePlan::query()->findOrFail($planId);

        $this->deletePlanId = $plan->id;
        $this->deletePlanLabel = $plan->name;

        $this->dispatch('payments-plans:modal-show', id: 'paymentPlanDeleteModal');
    }

    public function deletePlan(): void
    {
        $this->authorizeAdminChange('admin.pricing');

        $plan = ServicePlan::query()->findOrFail($this->deletePlanId);

        $hasDependencies = $plan->subscriptions()->exists()
            || $plan->previousSubscriptions()->exists()
            || $plan->pricingRules()->exists()
            || $plan->planEntitlements()->exists()
            || $plan->voiceAccesses()->exists()
            || $plan->monthlyGrants()->exists()
            || CreditOrder::query()->where('service_plan_id', $plan->id)->exists();

        if ($hasDependencies) {
            $this->dispatch(
                'alert',
                type: 'error',
                message: __('This plan has related subscriptions, pricing, entitlements, or orders. Deactivate it instead of deleting.')
            );

            return;
        }

        app(\App\Services\Admin\AdminCatalogDeletion::class)->delete($plan);
        unset($this->plans, $this->topStats);
        $this->resetDeleteState();
        $this->dispatch('payments-plans:modal-hide', id: 'paymentPlanDeleteModal');
        $this->dispatch('alert', type: 'success', message: __('Service plan deleted successfully.'));
    }

    public function resetPlanForm(): void
    {
        $this->editingPlanId = null;
        $this->code = '';
        $this->name = '';
        $this->billingIntervals = ['monthly'];
        $this->paymentMode = PaymentMode::RECURRING->value;
        $this->monthlyCredits = '';
        $this->appMonthlyCredits = '';
        $this->apiMonthlyCredits = 0;
        $this->concurrentJobsLimit = 2;
        $this->apiEnabled = false;
        $this->apiRequestsPerMinute = 0;
        $this->apiConcurrentJobs = 0;
        $this->apiAllowedToolsText = '';
        $this->priceIqdMonthly = '';
        $this->priceIqdYearly = '';
        $this->isFree = false;
        $this->isActive = true;
        $this->sortOrder = 0;
        $this->uiFeaturesJson = '';
        $this->metaJson = '';
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function resetDeleteState(): void
    {
        $this->deletePlanId = null;
        $this->deletePlanLabel = '';
    }

    protected function normalizePlanBillingIntervals(array $intervals): array
    {
        $allowed = ['monthly', 'yearly', 'lifetime'];
        $normalized = collect($intervals)
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : ['monthly'];
    }

    protected function primaryPlanBillingInterval(array $intervals): string
    {
        $intervals = $this->normalizePlanBillingIntervals($intervals);

        foreach (['monthly', 'yearly', 'lifetime'] as $preferred) {
            if (in_array($preferred, $intervals, true)) {
                return $preferred;
            }
        }

        return 'monthly';
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeApiAllowedToolsText(?string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', (string) $value) ?: [])
            ->map(fn ($scope): string => CustomerApiAccessService::canonicalScope((string) $scope))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

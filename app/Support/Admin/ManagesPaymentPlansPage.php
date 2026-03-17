<?php

namespace App\Support\Admin;

use App\Models\CreditOrder;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesPaymentPlansPage
{
    use InteractsWithPaymentAdmin;

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
    public string $billingInterval = 'monthly';
    public $monthlyCredits = '';
    public $priceUsdMonthly = '';
    public $priceUsdYearly = '';
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
        $allowed = ['sort_order', 'name', 'monthly_credits', 'price_usd_monthly', 'active_subscribers', 'paid_orders', 'revenue'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['monthly_credits', 'price_usd_monthly', 'active_subscribers', 'paid_orders', 'revenue'], true)
            ? 'desc'
            : 'asc';
    }

    protected function planFormRules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash|unique:service_plans,code,' . ($this->editingPlanId ?? 'NULL') . ',id',
            'name' => 'required|string|max:120',
            'billingInterval' => 'required|string|in:monthly,yearly,lifetime',
            'monthlyCredits' => 'required|integer|min:0',
            'priceUsdMonthly' => 'nullable|numeric|min:0',
            'priceUsdYearly' => 'nullable|numeric|min:0',
            'sortOrder' => 'nullable|integer|min:0|max:65535',
            'uiFeaturesJson' => 'nullable|string',
            'metaJson' => 'nullable|string',
        ];
    }

    #[Computed]
    public function topStats(): array
    {
        $activeSubscribers = (int) CustomerServiceSubscription::query()
            ->where('status', 'active')
            ->count();

        $orderSummary = CreditOrder::query()
            ->where('status', 'paid')
            ->whereNotNull('service_plan_id')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
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
        $activeSubscribers = CustomerServiceSubscription::query()
            ->where('status', 'active')
            ->groupBy('service_plan_id')
            ->selectRaw('service_plan_id')
            ->selectRaw('COUNT(*) as active_subscribers');

        $planRevenue = CreditOrder::query()
            ->where('status', 'paid')
            ->whereNotNull('service_plan_id')
            ->groupBy('service_plan_id')
            ->selectRaw('service_plan_id')
            ->selectRaw('COUNT(*) as paid_orders')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as revenue')
            ->selectRaw('COALESCE(SUM(credits_amount), 0) as credits_sold')
            ->selectRaw('MAX(created_at) as last_order_at');

        $query = ServicePlan::query()
            ->leftJoinSub($activeSubscribers, 'plan_active_subscribers', fn ($join) => $join->on('plan_active_subscribers.service_plan_id', '=', 'service_plans.id'))
            ->leftJoinSub($planRevenue, 'plan_revenue', fn ($join) => $join->on('plan_revenue.service_plan_id', '=', 'service_plans.id'))
            ->select('service_plans.*')
            ->selectRaw('COALESCE(plan_active_subscribers.active_subscribers, 0) as active_subscribers')
            ->selectRaw('COALESCE(plan_revenue.paid_orders, 0) as paid_orders')
            ->selectRaw('COALESCE(plan_revenue.revenue, 0) as revenue')
            ->selectRaw('COALESCE(plan_revenue.credits_sold, 0) as credits_sold')
            ->selectRaw('plan_revenue.last_order_at as last_order_at');

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('service_plans.name', 'like', "%{$search}%")
                    ->orWhere('service_plans.code', 'like', "%{$search}%")
                    ->orWhere('service_plans.billing_interval', 'like', "%{$search}%");
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
            'monthly_credits' => 'service_plans.monthly_credits',
            'price_usd_monthly' => 'service_plans.price_usd_monthly',
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

        $this->editingPlanId = $plan->id;
        $this->code = (string) $plan->code;
        $this->name = (string) $plan->name;
        $this->billingInterval = (string) $plan->billing_interval;
        $this->monthlyCredits = (int) ($plan->monthly_credits ?? 0);
        $this->priceUsdMonthly = (string) ((float) ($plan->price_usd_monthly ?? 0));
        $this->priceUsdYearly = (string) ((float) ($plan->price_usd_yearly ?? 0));
        $this->isFree = (bool) $plan->is_free;
        $this->isActive = (bool) $plan->is_active;
        $this->sortOrder = (int) ($plan->sort_order ?? 0);
        $this->uiFeaturesJson = $this->encodeJsonTextarea($plan->ui_features);
        $this->metaJson = $this->encodeJsonTextarea($plan->meta);
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-plans:modal-show', id: 'paymentPlanModal');
    }

    public function savePlan(): void
    {
        $validated = $this->validate($this->planFormRules());
        $uiFeatures = $this->decodeJsonTextarea($validated['uiFeaturesJson'] ?? '', 'uiFeaturesJson');
        $meta = $this->decodeJsonTextarea($validated['metaJson'] ?? '', 'metaJson');

        $plan = $this->editingPlanId
            ? ServicePlan::query()->findOrFail($this->editingPlanId)
            : new ServicePlan();
        $plan->fill([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'billing_interval' => $validated['billingInterval'],
            'monthly_credits' => (int) $validated['monthlyCredits'],
            'price_usd_monthly' => (float) ($validated['priceUsdMonthly'] !== '' ? $validated['priceUsdMonthly'] : 0),
            'price_usd_yearly' => (float) ($validated['priceUsdYearly'] !== '' ? $validated['priceUsdYearly'] : 0),
            'is_free' => (bool) $this->isFree,
            'is_active' => (bool) $this->isActive,
            'sort_order' => (int) ($validated['sortOrder'] ?? 0),
            'ui_features' => $uiFeatures,
            'meta' => $meta,
        ]);

        if ($plan->is_free) {
            $plan->price_usd_monthly = 0;
            $plan->price_usd_yearly = 0;
        }

        $plan->save();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingPlanId ? 'Service plan updated successfully.' : 'Service plan created successfully.'
        );

        $this->resetPlanForm();
        $this->dispatch('payments-plans:modal-hide', id: 'paymentPlanModal');
    }

    public function togglePlanStatus(int $planId): void
    {
        $plan = ServicePlan::query()->findOrFail($planId);
        $plan->update(['is_active' => !$plan->is_active]);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $plan->is_active ? 'Plan activated successfully.' : 'Plan deactivated successfully.'
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
                message: 'This plan has related subscriptions, pricing, entitlements, or orders. Deactivate it instead of deleting.'
            );

            return;
        }

        $plan->delete();
        $this->resetDeleteState();
        $this->dispatch('payments-plans:modal-hide', id: 'paymentPlanDeleteModal');
        $this->dispatch('alert', type: 'success', message: 'Service plan deleted successfully.');
    }

    public function resetPlanForm(): void
    {
        $this->editingPlanId = null;
        $this->code = '';
        $this->name = '';
        $this->billingInterval = 'monthly';
        $this->monthlyCredits = '';
        $this->priceUsdMonthly = '';
        $this->priceUsdYearly = '';
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
}

<?php

namespace App\Support\Admin;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerRegisterPage
{
    use InteractsWithCustomerAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'country', keep: true)]
    public string $countryFilter = 'all';

    #[Url(as: 'joined', keep: true)]
    public string $joinedFilter = 'all';

    #[Url(as: 'customer', keep: true)]
    public string $customerFilter = 'all';

    public int $perPage = 12;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCountryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedJoinedFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->planFilter = 'all';
        $this->countryFilter = 'all';
        $this->joinedFilter = 'all';
        $this->customerFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'customers' => (int) Customer::query()->count(),
            'new_30d' => (int) Customer::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'profiles' => (int) Customer::query()->whereHas('profile')->count(),
            'countries' => (int) $this->customerCountryOptions->count(),
        ];
    }

    protected function registerBaseQuery(): Builder
    {
        $query = $this->customersOverviewQuery();

        $this->applyCustomerSearch($query, $this->search);
        $this->applyCustomerStatusFilter($query, $this->statusFilter);
        $this->applyPlanFilter($query, $this->planFilter);
        $this->applyCountryFilter($query, $this->countryFilter);
        $this->applyJoinedWindowFilter($query, $this->joinedFilter);

        return $query
            ->orderByDesc('customers.created_at')
            ->orderBy('customers.username');
    }

    #[Computed]
    public function registrationCustomers()
    {
        return $this->registerBaseQuery()->paginate($this->perPage);
    }

    #[Computed]
    public function selectedCustomer()
    {
        if ($this->customerFilter === 'all') {
            return null;
        }

        return $this->customersOverviewQuery()
            ->withCount(['customerFiles', 'serviceSubscriptions'])
            ->with([
                'creditOrders' => fn ($orderQuery) => $this->scopePaidOrders($orderQuery)->latest()->limit(6),
                'creditOrders.servicePlan:id,code,name',
                'creditOrders.creditProduct:id,code,name,credits_amount',
                'serviceSubscriptions' => fn ($subscriptionQuery) => $subscriptionQuery->with(['servicePlan:id,code,name', 'previousServicePlan:id,code,name'])->latest()->limit(6),
                'mlJobs' => fn ($jobQuery) => $this->scopeJobs($jobQuery)->with(['tool:id,code,name', 'toolAction:id,tool_code,action_code,full_code,name'])->latest()->limit(5),
            ])
            ->find((int) $this->customerFilter);
    }

    public function focusCustomer(int $customerId): void
    {
        $this->customerFilter = (string) $customerId;
    }

    public function clearFocusedCustomer(): void
    {
        $this->customerFilter = 'all';
    }
}

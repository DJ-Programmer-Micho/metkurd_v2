<?php

namespace App\Support\Admin;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerSuspendedPage
{
    use InteractsWithCustomerAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'country', keep: true)]
    public string $countryFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'created_at';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'desc';

    public int $perPage = 12;

    public function updatingSearch(): void
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

    public function resetFilters(): void
    {
        $this->search = '';
        $this->planFilter = 'all';
        $this->countryFilter = 'all';
        $this->sortColumn = 'created_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['created_at', 'username', 'consumed_credits', 'paid_order_amount'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['created_at', 'consumed_credits', 'paid_order_amount'], true) ? 'desc' : 'asc';
    }

    #[Computed]
    public function topStats(): array
    {
        $base = Customer::query()->where('status', 0);

        return [
            'suspended' => (int) (clone $base)->count(),
            'with_balance' => (int) (clone $base)->whereHas('wallet', fn (Builder $walletQuery) => $walletQuery->where('balance_credits', '>', 0))->count(),
            'with_plan' => (int) (clone $base)->whereHas('servicePlan')->count(),
            'verified' => (int) (clone $base)->where('email_verify', true)->where('phone_verify', true)->count(),
        ];
    }

    protected function suspendedCustomersBaseQuery(): Builder
    {
        $query = $this->customersOverviewQuery()->where('status', 0);

        $this->applyCustomerSearch($query, $this->search);
        $this->applyPlanFilter($query, $this->planFilter);
        $this->applyCountryFilter($query, $this->countryFilter);

        $column = match ($this->sortColumn) {
            'username' => 'customers.username',
            'consumed_credits' => 'consumed_credits',
            'paid_order_amount' => 'paid_order_amount',
            default => 'customers.created_at',
        };

        return $query
            ->orderBy($column, $this->sortDirection)
            ->orderByDesc('customers.id');
    }

    #[Computed]
    public function suspendedCustomers()
    {
        return $this->suspendedCustomersBaseQuery()->paginate($this->perPage);
    }

    public function restoreCustomer(int $customerId): void
    {
        Customer::query()->whereKey($customerId)->update(['status' => 1]);
        $this->dispatch('alert', type: 'success', message: 'Customer restored successfully.');
    }
}

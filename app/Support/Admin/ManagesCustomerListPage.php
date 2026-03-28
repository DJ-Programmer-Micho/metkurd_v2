<?php

namespace App\Support\Admin;

use App\Models\Customer;
use App\Support\CustomerEmailNotifier;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerListPage
{
    use InteractsWithCustomerAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'verify', keep: true)]
    public string $verificationFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'created_at';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'desc';

    public int $perPage = 12;

    public ?int $viewingCustomerId = null;

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

    public function updatedVerificationFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->planFilter = 'all';
        $this->verificationFilter = 'all';
        $this->sortColumn = 'created_at';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['created_at', 'username', 'jobs_count', 'consumed_credits', 'paid_order_amount'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['jobs_count', 'consumed_credits', 'paid_order_amount', 'created_at'], true) ? 'desc' : 'asc';
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'customers' => (int) Customer::query()->count(),
            'active' => (int) Customer::query()->where('status', '!=', 0)->count(),
            'suspended' => (int) Customer::query()->where('status', 0)->count(),
            'verified' => (int) Customer::query()->where('email_verify', true)->where('phone_verify', true)->count(),
        ];
    }

    protected function customersBaseQuery(): Builder
    {
        $query = $this->customersOverviewQuery();

        $this->applyCustomerSearch($query, $this->search);
        $this->applyCustomerStatusFilter($query, $this->statusFilter);
        $this->applyPlanFilter($query, $this->planFilter);

        if ($this->verificationFilter === 'verified') {
            $query->where('email_verify', true)->where('phone_verify', true);
        } elseif ($this->verificationFilter === 'needs_attention') {
            $query->where(function (Builder $builder) {
                $builder
                    ->where('email_verify', false)
                    ->orWhere('phone_verify', false);
            });
        }

        $column = match ($this->sortColumn) {
            'username' => 'customers.username',
            'jobs_count' => 'jobs_count',
            'consumed_credits' => 'consumed_credits',
            'paid_order_amount' => 'paid_order_amount',
            default => 'customers.created_at',
        };

        return $query
            ->orderBy($column, $this->sortDirection)
            ->orderByDesc('customers.id');
    }

    #[Computed]
    public function customers()
    {
        return $this->customersBaseQuery()->paginate($this->perPage);
    }

    #[Computed]
    public function viewingCustomer()
    {
        if (!$this->viewingCustomerId) {
            return null;
        }

        return $this->customersOverviewQuery()
            ->withCount('customerFiles')
            ->with([
                'creditOrders' => fn ($orderQuery) => $this->scopePaidOrders($orderQuery)->latest()->limit(5),
                'creditOrders.servicePlan:id,code,name',
                'creditOrders.creditProduct:id,code,name,credits_amount',
                'mlJobs' => fn ($jobQuery) => $this->scopeJobs($jobQuery)->with(['tool:id,code,name', 'toolAction:id,tool_code,action_code,full_code,name'])->latest()->limit(6),
                'serviceSubscriptions' => fn ($subscriptionQuery) => $subscriptionQuery->with('servicePlan:id,code,name')->latest()->limit(5),
            ])
            ->find($this->viewingCustomerId);
    }

    public function openCustomerView(int $customerId): void
    {
        $this->viewingCustomerId = $customerId;
        $this->dispatch('customers-list:modal-show', id: 'customersListViewModal');
    }

    public function closeCustomerView(): void
    {
        $this->viewingCustomerId = null;
    }

    public function toggleCustomerStatus(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $nextStatus = (int) $customer->status === 0 ? 1 : 0;

        $customer->update(['status' => $nextStatus]);

        if ($nextStatus === 0) {
            CustomerEmailNotifier::sendAccountSuspended(
                $customer->fresh(['profile']),
                [
                    'reason' => 'Administrative review required',
                    'effective_date' => now()->format('F d, Y'),
                ],
                'Customer suspend action'
            );
        } else {
            CustomerEmailNotifier::sendAccountRecovered(
                $customer->fresh(['profile']),
                [
                    'recovered_at' => now()->format('Y-m-d H:i'),
                    'login_url' => route('app.signin'),
                ],
                'Customer restore action'
            );
        }

        $this->dispatch(
            'alert',
            type: 'success',
            message: $nextStatus === 0 ? __('Customer suspended successfully.') : __('Customer restored successfully.')
        );
    }
}

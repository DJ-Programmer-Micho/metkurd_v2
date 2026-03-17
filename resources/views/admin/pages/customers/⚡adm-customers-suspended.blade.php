<?php

use App\Support\Admin\ManagesCustomerSuspendedPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Customers Suspended | METKURD')]
class extends Component
{
    use ManagesCustomerSuspendedPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Suspended Customers</h4>
                    <p class="text-muted mb-0">Focused list of accounts where `customers.status = 0` so admin can review and restore them quickly.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">Back to List</a>
                    <button type="button" class="btn btn-soft-danger" wire:click="resetFilters">Clear Filters</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Suspended</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['suspended']) }}</h2>
                    <p class="text-muted mb-0">Accounts currently blocked from app access.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">With Credit Balance</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['with_balance']) }}</h2>
                    <p class="text-muted mb-0">Suspended customers that still hold credits.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">With Active Plan</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['with_plan']) }}</h2>
                    <p class="text-muted mb-0">Suspended customers that still resolve to a plan.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Fully Verified</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['verified']) }}</h2>
                    <p class="text-muted mb-0">Suspended accounts with completed email and phone verification.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-5">
                    <label class="form-label text-muted text-uppercase fs-12">Search</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search suspended customer, plan, or location...">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label text-muted text-uppercase fs-12">Plan</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">All plans</option>
                        <option value="none">No active plan</option>
                        @foreach ($this->customerPlanOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <label class="form-label text-muted text-uppercase fs-12">Country</label>
                    <select class="form-select" wire:model.live="countryFilter">
                        <option value="all">All countries</option>
                        @foreach ($this->customerCountryOptions as $country)
                            <option value="{{ $country }}">{{ $country }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <label class="form-label text-muted text-uppercase fs-12">Sort</label>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'created_at' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('created_at')">Newest</button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'consumed_credits' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('consumed_credits')">Usage</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">Suspended Accounts</h5>
            <p class="text-muted mb-0">Review balances, plan state, and usage before restoring an account.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Customer</th>
                            <th>Location</th>
                            <th>Plan / Wallet</th>
                            <th>Usage</th>
                            <th>Verification</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->suspendedCustomers as $customer)
                            <tr class="table-danger" wire:key="suspended-row-{{ $customer->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerDisplayName($customer) }}</span>
                                        <span class="text-muted small">{{ '@' . $customer->username }}</span>
                                        <span class="text-muted small">{{ $customer->email }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerLocation($customer) }}</span>
                                        <span class="text-muted small">Joined {{ $customer->created_at?->format('M d, Y') ?? 'n/a' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="badge {{ $this->planBadgeClasses($customer->servicePlan?->code) }}">{{ $customer->servicePlan?->name ?? 'No active plan' }}</span>
                                        <span class="text-muted small">Balance: {{ $this->formatCredits(data_get($customer, 'wallet.balance_credits')) }} credits</span>
                                        <span class="text-muted small">Spent: {{ $this->formatCredits(data_get($customer, 'wallet.lifetime_spent')) }} credits</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format((int) ($customer->jobs_count ?? 0)) }} jobs</span>
                                        <span class="text-muted small">{{ $this->formatCredits($customer->consumed_credits) }} credits consumed</span>
                                        <span class="text-muted small">{{ $this->formatMoney($customer->paid_order_amount) }} paid total</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <span class="badge {{ $this->verificationBadgeClasses((bool) $customer->email_verify) }}">Email</span>
                                        <span class="badge {{ $this->verificationBadgeClasses((bool) $customer->phone_verify) }}">Phone</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="restoreCustomer({{ $customer->id }})">Restore</button>
                                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">Register</a>
                                        <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-secondary">Usage</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">No suspended customers matched the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->suspendedCustomers->onEachSide(1)->links() }}
        </div>
    </div>
</div>

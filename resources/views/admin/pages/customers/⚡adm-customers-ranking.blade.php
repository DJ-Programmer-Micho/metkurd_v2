<?php

use App\Support\Admin\ManagesCustomerRankingPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('admin::layouts.app')]
#[Title('Customers Ranking | METKURD')]
class extends Component
{
    use ManagesCustomerRankingPage;
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Customer Rankings</h4>
                    <p class="text-muted mb-0">Track the highest consumers and the customers buying the most plan or addon credits.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <span class="badge bg-soft-info text-info">{{ $this->periodLabel($periodFilter) }}</span>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Customers in Scope</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['customers']) }}</h2>
                    <p class="text-muted mb-0">Customers matching the current search and plan filters.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Credits Consumed</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['credits_consumed']) }}</h2>
                    <p class="text-muted mb-0">Total usage charged to `MlJob` rows in this time window.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Credits Purchased</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['credits_purchased']) }}</h2>
                    <p class="text-muted mb-1">{{ $this->formatMoney($this->topStats['revenue']) }} total paid revenue.</p>
                    <div class="small text-muted">Plans {{ $this->formatMoney($this->topStats['service_plan_revenue']) }} • Storage {{ $this->formatMoney($this->topStats['storage_revenue']) }} • Products {{ $this->formatMoney($this->topStats['credit_product_revenue']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Current Leaders</p>
                    <div class="small text-muted mb-2">Consumption: {{ $this->topStats['top_consumer'] }}</div>
                    <div class="small text-muted">Purchases: {{ $this->topStats['top_buyer'] }}</div>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search customer, plan, location, or profile...">
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
                    <label class="form-label text-muted text-uppercase fs-12">Period</label>
                    <select class="form-select" wire:model.live="periodFilter">
                        <option value="7">Last 7 days</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="365">Last 12 months</option>
                        <option value="all">All time</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <label class="form-label text-muted text-uppercase fs-12">Leaderboard Size</label>
                    <select class="form-select" wire:model.live="rankingLimit">
                        <option value="8">Top 8</option>
                        <option value="12">Top 12</option>
                        <option value="20">Top 20</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6 mb-3">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">Consumption Ranking</h5>
                    <p class="text-muted mb-0">Sorted by credits consumed from tool runs.</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted">
                                <tr class="text-uppercase">
                                    <th style="width: 70px;">Rank</th>
                                    <th>Customer</th>
                                    <th>Plan</th>
                                    <th>Jobs</th>
                                    <th>Credits</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->consumptionRanking as $index => $customer)
                                    <tr wire:key="consumption-rank-{{ $customer->id }}">
                                        <td>
                                            <span class="badge {{ $index === 0 ? 'bg-warning-subtle text-warning' : 'bg-light text-body' }}">#{{ $index + 1 }}</span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->customerDisplayName($customer) }}</span>
                                                <span class="text-muted small">{{ '@' . $customer->username }}</span>
                                                <span class="text-muted small">{{ $this->customerLocation($customer) }}</span>
                                            </div>
                                        </td>
                                        <td><span class="badge {{ $this->planBadgeClasses($customer->servicePlan?->code) }}">{{ $customer->servicePlan?->name ?? 'No active plan' }}</span></td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ number_format((int) ($customer->jobs_count ?? 0)) }}</span>
                                                <span class="text-muted small">{{ number_format((int) ($customer->done_jobs_count ?? 0)) }} done / {{ number_format((int) ($customer->failed_jobs_count ?? 0)) }} failed</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->consumed_credits) }}</span>
                                                <span class="text-muted small">{{ $this->formatMoney($customer->paid_order_amount) }} lifetime paid</span>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end flex-wrap gap-2">
                                                <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $customer->id, 'period' => $periodFilter]) }}" class="btn btn-sm btn-soft-secondary">Usage</a>
                                                <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">Register</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">No consumption data matched the current filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-3">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">Purchase Ranking</h5>
                    <p class="text-muted mb-0">Sorted by total paid credits, with revenue split across service plans, storage plans, and credit products.</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted">
                                <tr class="text-uppercase">
                                    <th style="width: 70px;">Rank</th>
                                    <th>Customer</th>
                                    <th>Service Plans</th>
                                    <th>Storage Plans</th>
                                    <th>Credit Products</th>
                                    <th>Total Paid</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->purchaseRanking as $index => $customer)
                                    <tr wire:key="purchase-rank-{{ $customer->id }}">
                                        <td>
                                            <span class="badge {{ $index === 0 ? 'bg-success-subtle text-success' : 'bg-light text-body' }}">#{{ $index + 1 }}</span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->customerDisplayName($customer) }}</span>
                                                <span class="text-muted small">{{ '@' . $customer->username }}</span>
                                                <span class="text-muted small">{{ $customer->servicePlan?->name ?? 'No active plan' }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->service_plan_credits_bought) }}</span>
                                                <span class="text-muted small">{{ $this->formatMoney($customer->service_plan_amount_spent) }} from {{ number_format((int) ($customer->service_plan_orders_count ?? 0)) }} orders</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ number_format((int) ($customer->storage_orders_count ?? 0)) }} orders</span>
                                                <span class="text-muted small">{{ $this->formatMoney($customer->storage_amount_spent) }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->credit_product_credits_bought) }}</span>
                                                <span class="text-muted small">{{ $this->formatMoney($customer->credit_product_amount_spent) }} from {{ number_format((int) ($customer->credit_product_orders_count ?? 0)) }} orders</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->paid_order_credits) }}</span>
                                                <span class="text-muted small">{{ $this->formatMoney($customer->paid_order_amount) }}</span>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end flex-wrap gap-2">
                                                <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">Register</a>
                                                <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale(), 'q' => $customer->username]) }}" class="btn btn-sm btn-soft-info">Locate</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">No purchase data matched the current filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php

use App\Support\Admin\ManagesCustomerRankingPage;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesCustomerRankingPage;
};
?>

<x-slot:title>{{ __('Customers Ranking') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Customer Rankings') }}</h4>
                    <p class="text-muted mb-0">{{ __('Track the highest consumers and the customers buying the most plan or addon credits.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <span class="badge bg-soft-info text-info">{{ $this->periodLabel($periodFilter) }}</span>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Customers in Scope') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['customers']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Customers matching the current search and plan filters.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Credits Consumed') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['credits_consumed']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Total usage charged to `MlJob` rows in this time window.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Credits Purchased') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['credits_purchased']) }}</h2>
                    <p class="text-muted mb-1">{{ __(':amount total paid revenue.', ['amount' => $this->formatMoney($this->topStats['revenue'])]) }}</p>
                    <div class="small text-muted">{{ __('Plans :plans | Storage :storage | Products :products', ['plans' => $this->formatMoney($this->topStats['service_plan_revenue']), 'storage' => $this->formatMoney($this->topStats['storage_revenue']), 'products' => $this->formatMoney($this->topStats['credit_product_revenue'])]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Current Leaders') }}</p>
                    <div class="small text-muted mb-2">{{ __('Consumption: :name', ['name' => $this->topStats['top_consumer']]) }}</div>
                    <div class="small text-muted">{{ __('Purchases: :name', ['name' => $this->topStats['top_buyer']]) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-5">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-ranking-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search customer, plan, location, or profile...') }}" id="admin-field-adm-customers-ranking-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-ranking-2">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter" id="admin-field-adm-customers-ranking-2">
                        <option value="all">{{ __('All plans') }}</option>
                        <option value="none">{{ __('No active plan') }}</option>
                        @foreach ($this->customerPlanOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-ranking-3">{{ __('Period') }}</label>
                    <select class="form-select" wire:model.live="periodFilter" id="admin-field-adm-customers-ranking-3">
                        <option value="7">{{ __('Last 7 days') }}</option>
                        <option value="30">{{ __('Last 30 days') }}</option>
                        <option value="90">{{ __('Last 90 days') }}</option>
                        <option value="365">{{ __('Last 12 months') }}</option>
                        <option value="all">{{ __('All time') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-3">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-ranking-4">{{ __('Leaderboard Size') }}</label>
                    <select class="form-select" wire:model.live="rankingLimit" id="admin-field-adm-customers-ranking-4">
                        @foreach ([20, 40, 60, 80, 100] as $size)
                            <option value="{{ $size }}">1–{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6 mb-3">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Consumption Ranking') }}</h5>
                    <p class="text-muted mb-0">{{ __('Sorted by credits consumed from tool runs.') }}</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted">
                                <tr class="text-uppercase">
                                    <th style="width: 70px;">{{ __('Rank') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Plan') }}</th>
                                    <th>{{ __('Jobs') }}</th>
                                    <th>{{ __('Credits') }}</th>
                                    <th class="text-end">{{ __('Actions') }}</th>
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
                                        <td><span class="badge {{ $this->planBadgeClasses($customer->servicePlan?->code) }}">{{ $customer->servicePlan?->name ?? __('No active plan') }}</span></td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ number_format((int) ($customer->jobs_count ?? 0)) }}</span>
                                                <span class="text-muted small">{{ __(':done done / :failed failed', ['done' => number_format((int) ($customer->done_jobs_count ?? 0)), 'failed' => number_format((int) ($customer->failed_jobs_count ?? 0))]) }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->consumed_credits) }}</span>
                                                <span class="text-muted small">{{ __(':amount lifetime paid', ['amount' => $this->formatMoney($customer->paid_order_amount)]) }}</span>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end flex-wrap gap-2">
                                                <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $customer->id, 'period' => $periodFilter]) }}" class="btn btn-sm btn-soft-secondary">{{ __('Usage') }}</a>
                                                <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">{{ __('Register') }}</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">{{ __('No consumption data matched the current filters.') }}</td>
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
                    <h5 class="card-title mb-1">{{ __('Purchase Ranking') }}</h5>
                    <p class="text-muted mb-0">{{ __('Sorted by total paid credits, with revenue split across service plans, storage plans, and credit products.') }}</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted">
                                <tr class="text-uppercase">
                                    <th style="width: 70px;">{{ __('Rank') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Service Plans') }}</th>
                                    <th>{{ __('Storage Plans') }}</th>
                                    <th>{{ __('Credit Products') }}</th>
                                    <th>{{ __('Total Paid') }}</th>
                                    <th class="text-end">{{ __('Actions') }}</th>
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
                                                <span class="text-muted small">{{ $customer->servicePlan?->name ?? __('No active plan') }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->service_plan_credits_bought) }}</span>
                                                <span class="text-muted small">{{ __(':amount from :count orders', ['amount' => $this->formatMoney($customer->service_plan_amount_spent), 'count' => number_format((int) ($customer->service_plan_orders_count ?? 0))]) }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ __(':count orders', ['count' => number_format((int) ($customer->storage_orders_count ?? 0))]) }}</span>
                                                <span class="text-muted small">{{ $this->formatMoney($customer->storage_amount_spent) }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $this->formatCredits($customer->credit_product_credits_bought) }}</span>
                                                <span class="text-muted small">{{ __(':amount from :count orders', ['amount' => $this->formatMoney($customer->credit_product_amount_spent), 'count' => number_format((int) ($customer->credit_product_orders_count ?? 0))]) }}</span>
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
                                                <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">{{ __('Register') }}</a>
                                                <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale(), 'q' => $customer->username]) }}" class="btn btn-sm btn-soft-info">{{ __('Locate') }}</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">{{ __('No purchase data matched the current filters.') }}</td>
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

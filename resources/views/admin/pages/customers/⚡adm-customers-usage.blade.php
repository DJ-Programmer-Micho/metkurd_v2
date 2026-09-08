<?php

use App\Support\Admin\ManagesCustomerUsagePage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesCustomerUsagePage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Customers Usage') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-customer-context :customer-id="(int) $customerFilter" :name="$this->selectedCustomer?->username" />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Customer Usage') }}</h4>
                    <p class="text-muted mb-0">{{ __('Analyze customer usage across all `MlJob` tool and action activity to identify the heaviest consumers and most used tools.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    @if ($this->selectedCustomer)
                        <button type="button" class="btn btn-soft-info" wire:click="clearFocusedCustomer">{{ __('Clear Focus') }}</button>
                    @endif
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Jobs') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['jobs']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':period in the current customer scope.', ['period' => $this->periodLabel($periodFilter)]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Credits Consumed') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['credits']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Charged credits from all non-deleted jobs in scope.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Customers with Activity') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['customers']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Distinct customers who have matching jobs in this view.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Most Used Tool') }}</p>
                    <h6 class="mb-0">{{ $this->topStats['top_tool'] }}</h6>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-usage-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search customer username, email, or profile...') }}" id="admin-field-adm-customers-usage-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-usage-2">{{ __('Focused Customer') }}</label>
                    <input class="form-control mb-2" wire:model.live.debounce.400ms="customerDirectorySearch" maxlength="100" dir="auto" aria-label="{{ __('admin_p2.customer_search') }}" placeholder="{{ __('admin_p2.customer_search') }}" id="admin-field-adm-customers-usage-2">
                    <select class="form-select" wire:model.live="customerFilter">
                        <option value="all">{{ __('All customers') }}</option>
                        @foreach ($this->customerDirectoryOptions as $customerOption)
                            <option value="{{ $customerOption->id }}">{{ $customerOption->username }} ({{ $customerOption->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-usage-3">{{ __('Period') }}</label>
                    <select class="form-select" wire:model.live="periodFilter" id="admin-field-adm-customers-usage-3">
                        <option value="7">{{ __('Last 7 days') }}</option>
                        <option value="30">{{ __('Last 30 days') }}</option>
                        <option value="90">{{ __('Last 90 days') }}</option>
                        <option value="365">{{ __('Last 12 months') }}</option>
                        <option value="all">{{ __('All time') }}</option>
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-usage-4">{{ __('Job Status') }}</label>
                    <select class="form-select" wire:model.live="jobStatusFilter" id="admin-field-adm-customers-usage-4">
                        <option value="all">{{ __('All non-deleted jobs') }}</option>
                        <option value="done">{{ __('Completed only') }}</option>
                        <option value="failed">{{ __('Failed only') }}</option>
                        <option value="active">{{ __('Queued / running / saving') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    @if ($this->selectedCustomer)
        <div class="card border-info mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="mb-1">{{ __('Usage Focus: :name', ['name' => $this->customerDisplayName($this->selectedCustomer)]) }}</h5>
                    <p class="text-muted mb-0">
                        {{ __(':jobs jobs, :credits credits, plan :plan.', ['jobs' => number_format((int) ($this->selectedCustomer->jobs_count ?? 0)), 'credits' => $this->formatCredits($this->selectedCustomer->consumed_credits), 'plan' => $this->selectedCustomer->servicePlan?->name ?? __('No active plan')]) }}
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $this->selectedCustomer->id]) }}" class="btn btn-soft-primary">{{ __('Open Register') }}</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="clearFocusedCustomer">{{ __('Show All Customers') }}</button>
                </div>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">{{ __('Customer Usage Table') }}</h5>
            <p class="text-muted mb-0">{{ __('See which customers are consuming the most credits and how much of that usage is successful versus failed.') }}</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Jobs') }}</th>
                            <th>{{ __('Credits') }}</th>
                            <th>{{ __('Storage') }}</th>
                            <th>{{ __('Last Job') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->customerUsageRows as $customer)
                            <tr wire:key="usage-customer-{{ $customer->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerDisplayName($customer) }}</span>
                                        <span class="text-muted small">{{ '@' . $customer->username }}</span>
                                        <span class="text-muted small">{{ $customer->email }}</span>
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
                                        <span class="text-muted small">{{ __(':amount paid', ['amount' => $this->formatMoney($customer->paid_order_amount)]) }}</span>
                                    </div>
                                </td>
                                <td>{{ $this->formatBytes(data_get($customer, 'usage.storage_used_bytes')) }}</td>
                                <td>{{ $customer->last_job_at ? \Illuminate\Support\Carbon::parse($customer->last_job_at)->diffForHumans() : __('No jobs yet') }}</td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="focusCustomer({{ $customer->id }})">{{ __('Focus') }}</button>
                                        <a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}">{{ __('admin_p2.operations') }}</a>
                                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">{{ __('Register') }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">{{ __('No usage rows matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->customerUsageRows->onEachSide(1)->links() }}
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">{{ $this->selectedCustomer ? __('Focused Tool and Action Breakdown') : __('Global Tool and Action Breakdown') }}</h5>
            <p class="text-muted mb-0">{{ __('Grouped by tool and action so admin can quickly spot the most used integrations and entry points.') }}</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Tool') }}</th>
                            <th>{{ __('Action') }}</th>
                            <th>{{ __('Jobs') }}</th>
                            <th>{{ __('Done') }}</th>
                            <th>{{ __('Failed') }}</th>
                            <th>{{ __('Credits') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->toolBreakdown as $row)
                            <tr wire:key="usage-breakdown-{{ md5($row->tool_code . '|' . $row->action_code) }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $row->tool_name }}</span>
                                        <span class="text-muted small">{{ $row->tool_code }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $row->action_name }}</span>
                                        <span class="text-muted small">{{ $row->action_code }}</span>
                                    </div>
                                </td>
                                <td>{{ number_format((int) ($row->jobs ?? 0)) }}</td>
                                <td>{{ number_format((int) ($row->done_jobs ?? 0)) }}</td>
                                <td>{{ number_format((int) ($row->failed_jobs ?? 0)) }}</td>
                                <td>{{ $this->formatCredits($row->consumed_credits ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">{{ __('No tool usage breakdown is available for the current scope.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

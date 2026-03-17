<?php

use App\Support\Admin\ManagesCustomerUsagePage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Customers Usage | METKURD')]
class extends Component
{
    use ManagesCustomerUsagePage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Customer Usage</h4>
                    <p class="text-muted mb-0">Analyze customer usage across all `MlJob` tool and action activity to identify the heaviest consumers and most used tools.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    @if ($this->selectedCustomer)
                        <button type="button" class="btn btn-soft-info" wire:click="clearFocusedCustomer">Clear Focus</button>
                    @endif
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Jobs</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['jobs']) }}</h2>
                    <p class="text-muted mb-0">{{ $this->periodLabel($periodFilter) }} in the current customer scope.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Credits Consumed</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['credits']) }}</h2>
                    <p class="text-muted mb-0">Charged credits from all non-deleted jobs in scope.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Customers with Activity</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['customers']) }}</h2>
                    <p class="text-muted mb-0">Distinct customers who have matching jobs in this view.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Most Used Tool</p>
                    <h6 class="mb-0">{{ $this->topStats['top_tool'] }}</h6>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12">Search</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search customer username, email, or profile...">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Focused Customer</label>
                    <select class="form-select" wire:model.live="customerFilter">
                        <option value="all">All customers</option>
                        @foreach ($this->customerDirectoryOptions as $customerOption)
                            <option value="{{ $customerOption->id }}">{{ $customerOption->username }} ({{ $customerOption->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Period</label>
                    <select class="form-select" wire:model.live="periodFilter">
                        <option value="7">Last 7 days</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="365">Last 12 months</option>
                        <option value="all">All time</option>
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Job Status</label>
                    <select class="form-select" wire:model.live="jobStatusFilter">
                        <option value="all">All non-deleted jobs</option>
                        <option value="done">Completed only</option>
                        <option value="failed">Failed only</option>
                        <option value="active">Queued / running / saving</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    @if ($this->selectedCustomer)
        <div class="card border-info mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="mb-1">Usage Focus: {{ $this->customerDisplayName($this->selectedCustomer) }}</h5>
                    <p class="text-muted mb-0">
                        {{ number_format((int) ($this->selectedCustomer->jobs_count ?? 0)) }} jobs,
                        {{ $this->formatCredits($this->selectedCustomer->consumed_credits) }} credits,
                        plan {{ $this->selectedCustomer->servicePlan?->name ?? 'No active plan' }}.
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $this->selectedCustomer->id]) }}" class="btn btn-soft-primary">Open Register</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="clearFocusedCustomer">Show All Customers</button>
                </div>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">Customer Usage Table</h5>
            <p class="text-muted mb-0">See which customers are consuming the most credits and how much of that usage is successful versus failed.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Customer</th>
                            <th>Plan</th>
                            <th>Jobs</th>
                            <th>Credits</th>
                            <th>Storage</th>
                            <th>Last Job</th>
                            <th class="text-end">Actions</th>
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
                                        <span class="text-muted small">{{ $this->formatMoney($customer->paid_order_amount) }} paid</span>
                                    </div>
                                </td>
                                <td>{{ $this->formatBytes(data_get($customer, 'usage.storage_used_bytes')) }}</td>
                                <td>{{ $customer->last_job_at ? \Illuminate\Support\Carbon::parse($customer->last_job_at)->diffForHumans() : 'No jobs yet' }}</td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="focusCustomer({{ $customer->id }})">Focus</button>
                                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">Register</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No usage rows matched the current filters.</td>
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
            <h5 class="card-title mb-1">{{ $this->selectedCustomer ? 'Focused Tool and Action Breakdown' : 'Global Tool and Action Breakdown' }}</h5>
            <p class="text-muted mb-0">Grouped by tool and action so admin can quickly spot the most used integrations and entry points.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Tool</th>
                            <th>Action</th>
                            <th>Jobs</th>
                            <th>Done</th>
                            <th>Failed</th>
                            <th>Credits</th>
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
                                <td colspan="6" class="text-center py-5 text-muted">No tool usage breakdown is available for the current scope.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

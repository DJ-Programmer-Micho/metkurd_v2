<?php

use App\Support\Admin\ManagesCustomerRegisterPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Customers Register | METKURD')]
class extends Component
{
    use ManagesCustomerRegisterPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Customer Register</h4>
                    <p class="text-muted mb-0">Review join date, profile completeness, location, and registration history at account level.</p>
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
                    <p class="text-uppercase fw-medium text-muted mb-1">Customers</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['customers']) }}</h2>
                    <p class="text-muted mb-0">All registered customer accounts.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Joined in 30 Days</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['new_30d']) }}</h2>
                    <p class="text-muted mb-0">Recent registrations for onboarding review.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Profile Coverage</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['profiles']) }}</h2>
                    <p class="text-muted mb-0">Customers with a linked profile record.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Countries</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['countries']) }}</h2>
                    <p class="text-muted mb-0">Distinct profile countries represented in the registry.</p>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search customer, plan, location, or profile...">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Status</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Plan</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">All plans</option>
                        <option value="none">No active plan</option>
                        @foreach ($this->customerPlanOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Country</label>
                    <select class="form-select" wire:model.live="countryFilter">
                        <option value="all">All countries</option>
                        @foreach ($this->customerCountryOptions as $country)
                            <option value="{{ $country }}">{{ $country }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Joined</label>
                    <select class="form-select" wire:model.live="joinedFilter">
                        <option value="all">All time</option>
                        <option value="7">Last 7 days</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="365">Last 12 months</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    @if ($this->selectedCustomer)
        @php
            $focusedCustomer = $this->selectedCustomer;
            $focusedWallet = $focusedCustomer->wallet;
            $focusedPlan = $focusedCustomer->servicePlan;
        @endphp
        <div class="row mb-3">
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">Focused Customer</h5>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-1">{{ $this->customerDisplayName($focusedCustomer) }}</h5>
                        <p class="text-muted mb-3">{{ '@' . $focusedCustomer->username }} • {{ $focusedCustomer->email }}</p>
                        <div class="mb-2"><span class="fw-semibold">Joined:</span> {{ $focusedCustomer->created_at?->format('M d, Y H:i') ?? 'n/a' }}</div>
                        <div class="mb-2"><span class="fw-semibold">Location:</span> {{ $this->customerLocation($focusedCustomer) }}</div>
                        <div class="mb-2"><span class="fw-semibold">Job title:</span> {{ data_get($focusedCustomer, 'profile.job_title', 'Not set') }}</div>
                        <div class="mb-2"><span class="fw-semibold">Brand:</span> {{ data_get($focusedCustomer, 'profile.brand_name', 'Not set') }}</div>
                        <div class="mb-2"><span class="fw-semibold">Phone:</span> {{ data_get($focusedCustomer, 'profile.phone_number', 'Not set') }}</div>
                        <div><span class="badge {{ $this->customerStatusBadgeClasses($focusedCustomer->status) }}">{{ $this->customerStatusLabel($focusedCustomer->status) }}</span></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">Account Snapshot</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2"><span class="fw-semibold">Plan:</span> {{ $focusedPlan?->name ?? 'No active plan' }}</div>
                        <div class="mb-2"><span class="fw-semibold">Balance:</span> {{ $this->formatCredits($focusedWallet?->balance_credits) }} credits</div>
                        <div class="mb-2"><span class="fw-semibold">Lifetime spent:</span> {{ $this->formatCredits($focusedWallet?->lifetime_spent) }}</div>
                        <div class="mb-2"><span class="fw-semibold">Storage used:</span> {{ $this->formatBytes(data_get($focusedCustomer, 'usage.storage_used_bytes')) }}</div>
                        <div class="mb-2"><span class="fw-semibold">File records:</span> {{ number_format((int) ($focusedCustomer->customer_files_count ?? 0)) }}</div>
                        <div><span class="fw-semibold">Subscription records:</span> {{ number_format((int) ($focusedCustomer->service_subscriptions_count ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">Verification and Activity</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2"><span class="fw-semibold">Email verification:</span> {{ $focusedCustomer->email_verify ? 'Verified' : 'Pending' }}</div>
                        <div class="mb-2"><span class="fw-semibold">Phone verification:</span> {{ $focusedCustomer->phone_verify ? 'Verified' : 'Pending' }}</div>
                        <div class="mb-2"><span class="fw-semibold">Jobs:</span> {{ number_format((int) ($focusedCustomer->jobs_count ?? 0)) }}</div>
                        <div class="mb-2"><span class="fw-semibold">Credits consumed:</span> {{ $this->formatCredits($focusedCustomer->consumed_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">Paid orders:</span> {{ number_format((int) ($focusedCustomer->paid_orders_count ?? 0)) }}</div>
                        <div class="mb-2"><span class="fw-semibold">Credits bought:</span> {{ $this->formatCredits($focusedCustomer->paid_order_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">Paid revenue:</span> {{ $this->formatMoney($focusedCustomer->paid_order_amount) }}</div>
                        <div class="small text-muted">Plans {{ $this->formatMoney($focusedCustomer->service_plan_amount_spent) }} • Storage {{ $this->formatMoney($focusedCustomer->storage_amount_spent) }} • Products {{ $this->formatMoney($focusedCustomer->credit_product_amount_spent) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-xl-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">Recent Subscription History</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Plan</th>
                                        <th>Status</th>
                                        <th>Cycle</th>
                                        <th>Started</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($focusedCustomer->serviceSubscriptions as $subscription)
                                        <tr>
                                            <td>{{ $subscription->servicePlan?->name ?? 'Unknown plan' }}</td>
                                            <td><span class="badge bg-light text-body">{{ $subscription->status }}</span></td>
                                            <td>{{ $subscription->cycle_started_on?->format('M d, Y') ?? 'n/a' }} - {{ $subscription->cycle_ends_on?->format('M d, Y') ?? 'n/a' }}</td>
                                            <td>{{ $subscription->starts_at?->format('M d, Y') ?? 'n/a' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-3 text-muted">No subscription history recorded.</td>
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
                        <h5 class="card-title mb-0">Recent Jobs and Orders</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6 class="text-uppercase text-muted fs-12">Latest Jobs</h6>
                            @forelse ($focusedCustomer->mlJobs as $job)
                                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                                    <div>
                                        <div class="fw-semibold">{{ $job->tool?->name ?? $job->toolAction?->tool?->name ?? 'Unknown tool' }}</div>
                                        <div class="text-muted small">{{ $job->toolAction?->full_code ?? $job->job_kind }}</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ $this->formatCredits($job->credits_charged) }} credits</div>
                                        <div class="text-muted small">{{ $job->created_at?->diffForHumans() ?? 'n/a' }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-muted">No job history yet.</div>
                            @endforelse
                        </div>
                        <div>
                            <h6 class="text-uppercase text-muted fs-12">Latest Paid Orders</h6>
                            @forelse ($focusedCustomer->creditOrders as $order)
                                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                                    <div>
                                        <div class="fw-semibold">{{ $this->paymentSourceLabel($order->source_type, $order->order_type) }}</div>
                                        <div class="text-muted small">{{ $order->servicePlan?->name ?? $order->creditProduct?->name ?? ($order->source_type ?? 'n/a') }}</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ $this->formatMoney($order->amount_usd) }}</div>
                                        <div class="text-muted small">{{ $order->created_at?->format('M d, Y') ?? 'n/a' }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-muted">No paid orders yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">Register Table</h5>
            <p class="text-muted mb-0">Sort recent joins, profile coverage, location, and plan state for onboarding and support workflows.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Customer</th>
                            <th>Joined</th>
                            <th>Location</th>
                            <th>Plan</th>
                            <th>Profile</th>
                            <th>Verification</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->registrationCustomers as $customer)
                            <tr wire:key="register-row-{{ $customer->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerDisplayName($customer) }}</span>
                                        <span class="text-muted small">{{ '@' . $customer->username }}</span>
                                        <span class="text-muted small">{{ $customer->email }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $customer->created_at?->format('M d, Y') ?? 'n/a' }}</span>
                                        <span class="text-muted small">{{ $customer->created_at?->diffForHumans() ?? '' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerLocation($customer) }}</span>
                                        <span class="text-muted small">{{ data_get($customer, 'profile.address', 'No address provided') }}</span>
                                    </div>
                                </td>
                                <td><span class="badge {{ $this->planBadgeClasses($customer->servicePlan?->code) }}">{{ $customer->servicePlan?->name ?? 'No active plan' }}</span></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ data_get($customer, 'profile.job_title', 'No job title') }}</span>
                                        <span class="text-muted small">{{ data_get($customer, 'profile.brand_name', 'No brand') }}</span>
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
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="focusCustomer({{ $customer->id }})">Focus</button>
                                        <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-secondary">Usage</a>
                                        <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale(), 'q' => $customer->username]) }}" class="btn btn-sm btn-soft-primary">Locate</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No customer registrations matched the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->registrationCustomers->onEachSide(1)->links() }}
        </div>
    </div>
</div>

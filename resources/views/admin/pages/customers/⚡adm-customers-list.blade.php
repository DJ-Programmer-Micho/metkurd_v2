<?php

use App\Support\Admin\ManagesCustomerListPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Customers List | METKURD')]
class extends Component
{
    use ManagesCustomerListPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Customers Directory</h4>
                    <p class="text-muted mb-0">Search, verify, suspend, and inspect customer accounts from one operational table.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.customers.suspended', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-danger">Suspended List</a>
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
                    <p class="text-muted mb-0">Total accounts in the customer registry.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Active Accounts</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active']) }}</h2>
                    <p class="text-muted mb-0">Accounts currently able to access the application.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Suspended</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['suspended']) }}</h2>
                    <p class="text-muted mb-0">Accounts with `status = 0` and blocked access.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Fully Verified</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['verified']) }}</h2>
                    <p class="text-muted mb-0">Accounts with both email and phone verification completed.</p>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search username, email, plan, location, or profile...">
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
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Verification</label>
                    <select class="form-select" wire:model.live="verificationFilter">
                        <option value="all">Any verification state</option>
                        <option value="verified">Fully verified</option>
                        <option value="needs_attention">Needs attention</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">Customer List</h5>
                    <p class="text-muted mb-0">Use the quick view modal for context, then jump directly into register or usage pages when deeper follow-up is needed.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'created_at' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('created_at')">
                        Newest
                        @if ($sortColumn === 'created_at')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'paid_order_amount' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('paid_order_amount')">
                        Spend
                        @if ($sortColumn === 'paid_order_amount')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'consumed_credits' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('consumed_credits')">
                        Usage
                        @if ($sortColumn === 'consumed_credits')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'username' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('username')">
                        Name
                        @if ($sortColumn === 'username')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Customer</th>
                            <th>Contact / Location</th>
                            <th>Plan / Wallet</th>
                            <th>Usage Snapshot</th>
                            <th>Verification</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->customers as $customer)
                            @php
                                $plan = $customer->servicePlan;
                                $wallet = $customer->wallet;
                                $subscription = $customer->activeServiceSubscription;
                            @endphp
                            <tr wire:key="customer-row-{{ $customer->id }}">
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
                                        <span class="text-muted small">{{ data_get($customer, 'profile.phone_number', 'No phone on file') }}</span>
                                        <span class="text-muted small">Joined {{ $customer->created_at?->format('M d, Y') ?? 'n/a' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge {{ $this->planBadgeClasses($plan?->code) }}">{{ $plan?->name ?? 'No active plan' }}</span>
                                        <span class="text-muted small">Balance: {{ $this->formatCredits($wallet?->balance_credits) }} credits</span>
                                        <span class="text-muted small">Spent: {{ $this->formatCredits($wallet?->lifetime_spent) }} credits</span>
                                        @if ($subscription?->cycle_ends_on)
                                            <span class="text-muted small">Cycle ends {{ $subscription->cycle_ends_on->format('M d, Y') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format((int) ($customer->jobs_count ?? 0)) }} jobs</span>
                                        <span class="text-muted small">{{ $this->formatCredits($customer->consumed_credits) }} credits consumed</span>
                                        <span class="text-muted small">{{ $this->formatMoney($customer->paid_order_amount) }} paid total</span>
                                        <span class="text-muted small">Plans {{ $this->formatMoney($customer->service_plan_amount_spent) }} • Storage {{ $this->formatMoney($customer->storage_amount_spent) }} • Products {{ $this->formatMoney($customer->credit_product_amount_spent) }}</span>
                                        <span class="text-muted small">
                                            {{ $customer->last_job_at ? \Illuminate\Support\Carbon::parse($customer->last_job_at)->diffForHumans() : 'No jobs yet' }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <span class="badge {{ $this->verificationBadgeClasses((bool) $customer->email_verify) }}">Email {{ $customer->email_verify ? 'OK' : 'Pending' }}</span>
                                        <span class="badge {{ $this->verificationBadgeClasses((bool) $customer->phone_verify) }}">Phone {{ $customer->phone_verify ? 'OK' : 'Pending' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge {{ $this->customerStatusBadgeClasses($customer->status) }}">{{ $this->customerStatusLabel($customer->status) }}</span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openCustomerView({{ $customer->id }})">View</button>
                                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-primary">Register</a>
                                        <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-secondary">Usage</a>
                                        <button type="button" class="btn btn-sm {{ (int) $customer->status === 0 ? 'btn-soft-success' : 'btn-soft-danger' }}" wire:click="toggleCustomerStatus({{ $customer->id }})">
                                            {{ (int) $customer->status === 0 ? 'Restore' : 'Suspend' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No customers matched the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->customers->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="customersListViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">Customer Quick View</h5>
                        <p class="text-muted mb-0">Operational context without leaving the directory screen.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="closeCustomerView"></button>
                </div>
                <div class="modal-body">
                    @if ($this->viewingCustomer)
                        @php
                            $focusedCustomer = $this->viewingCustomer;
                            $focusedWallet = $focusedCustomer->wallet;
                            $focusedPlan = $focusedCustomer->servicePlan;
                        @endphp
                        <div class="row g-3 mb-3">
                            <div class="col-lg-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                        <div>
                                            <h5 class="mb-1">{{ $this->customerDisplayName($focusedCustomer) }}</h5>
                                            <p class="text-muted mb-0">{{ '@' . $focusedCustomer->username }}</p>
                                        </div>
                                        <span class="badge {{ $this->customerStatusBadgeClasses($focusedCustomer->status) }}">{{ $this->customerStatusLabel($focusedCustomer->status) }}</span>
                                    </div>
                                    <div class="small text-muted mb-2">{{ $focusedCustomer->email }}</div>
                                    <div class="small mb-2">{{ $this->customerLocation($focusedCustomer) }}</div>
                                    <div class="small mb-2">{{ data_get($focusedCustomer, 'profile.phone_number', 'No phone') }}</div>
                                    <div class="small mb-2">Joined {{ $focusedCustomer->created_at?->format('M d, Y H:i') ?? 'n/a' }}</div>
                                    <div class="small">Brand: {{ data_get($focusedCustomer, 'profile.brand_name', 'Not set') }}</div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">Plan and Wallet</h6>
                                    <div class="mb-2"><span class="fw-semibold">Plan:</span> {{ $focusedPlan?->name ?? 'No active plan' }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Balance:</span> {{ $this->formatCredits($focusedWallet?->balance_credits) }} credits</div>
                                    <div class="mb-2"><span class="fw-semibold">Lifetime earned:</span> {{ $this->formatCredits($focusedWallet?->lifetime_earned) }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Lifetime spent:</span> {{ $this->formatCredits($focusedWallet?->lifetime_spent) }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Purchased credits:</span> {{ $this->formatCredits($focusedCustomer->paid_order_credits) }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Paid revenue:</span> {{ $this->formatMoney($focusedCustomer->paid_order_amount) }}</div>
                                    <div class="small text-muted mb-2">Plans {{ $this->formatMoney($focusedCustomer->service_plan_amount_spent) }} • Storage {{ $this->formatMoney($focusedCustomer->storage_amount_spent) }} • Products {{ $this->formatMoney($focusedCustomer->credit_product_amount_spent) }}</div>
                                    <div><span class="fw-semibold">Storage used:</span> {{ $this->formatBytes(data_get($focusedCustomer, 'usage.storage_used_bytes')) }}</div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">Verification and Activity</h6>
                                    <div class="mb-2"><span class="fw-semibold">Email:</span> {{ $focusedCustomer->email_verify ? 'Verified' : 'Pending' }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Phone:</span> {{ $focusedCustomer->phone_verify ? 'Verified' : 'Pending' }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Jobs:</span> {{ number_format((int) ($focusedCustomer->jobs_count ?? 0)) }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Credits consumed:</span> {{ $this->formatCredits($focusedCustomer->consumed_credits) }}</div>
                                    <div class="mb-2"><span class="fw-semibold">Paid orders:</span> {{ number_format((int) ($focusedCustomer->paid_orders_count ?? 0)) }}</div>
                                    <div><span class="fw-semibold">Files:</span> {{ number_format((int) ($focusedCustomer->customer_files_count ?? 0)) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h6 class="mb-0">Recent Jobs</h6>
                                        <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $focusedCustomer->id]) }}" class="btn btn-sm btn-soft-secondary">Open Usage</a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Tool</th>
                                                    <th>Status</th>
                                                    <th>Credits</th>
                                                    <th>When</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($focusedCustomer->mlJobs as $job)
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex flex-column">
                                                                <span class="fw-semibold">{{ $job->tool?->name ?? $job->toolAction?->tool?->name ?? 'Unknown tool' }}</span>
                                                                <span class="text-muted small">{{ $job->toolAction?->full_code ?? $job->job_kind }}</span>
                                                            </div>
                                                        </td>
                                                        <td><span class="badge bg-light text-body">{{ $job->status }}</span></td>
                                                        <td>{{ $this->formatCredits($job->credits_charged) }}</td>
                                                        <td>{{ $job->created_at?->diffForHumans() ?? 'n/a' }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="4" class="text-center py-3 text-muted">No jobs recorded for this customer yet.</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h6 class="mb-0">Recent Purchases</h6>
                                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $focusedCustomer->id]) }}" class="btn btn-sm btn-soft-primary">Open Register</a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Credits</th>
                                                    <th>Amount</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($focusedCustomer->creditOrders as $order)
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex flex-column">
                                                                <span class="fw-semibold">{{ $this->paymentSourceLabel($order->source_type, $order->order_type) }}</span>
                                                                <span class="text-muted small">{{ $order->servicePlan?->name ?? $order->creditProduct?->name ?? ($order->source_type ?? 'Manual') }}</span>
                                                            </div>
                                                        </td>
                                                        <td>{{ $this->formatCredits($order->credits_amount) }}</td>
                                                        <td>{{ $this->formatMoney($order->amount_usd) }}</td>
                                                        <td>{{ $order->created_at?->format('M d, Y') ?? 'n/a' }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="4" class="text-center py-3 text-muted">No paid credit orders recorded.</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="text-center py-5 text-muted">Select a customer from the directory to inspect account details.</div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($this->viewingCustomer)
                        <a wire:navigate href="{{ route('admin.customers.register', ['locale' => app()->getLocale(), 'customer' => $this->viewingCustomer->id]) }}" class="btn btn-primary">Open Register</a>
                    @endif
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="closeCustomerView">Close</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__CUSTOMERS_LIST_MODAL_EVENTS__) {
                        return;
                    }

                    window.__CUSTOMERS_LIST_MODAL_EVENTS__ = true;

                    const modalIds = ['customersListViewModal'];

                    const cleanupModalState = () => {
                        if (typeof bootstrap === 'undefined') {
                            return;
                        }

                        modalIds.forEach((id) => {
                            const element = document.getElementById(id);

                            if (!element) {
                                return;
                            }

                            const instance = bootstrap.Modal.getInstance(element);

                            if (instance) {
                                instance.hide();
                                instance.dispose();
                            }

                            element.classList.remove('show');
                            element.style.display = 'none';
                            element.removeAttribute('aria-modal');
                            element.removeAttribute('role');
                        });

                        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                        document.body.classList.remove('modal-open');
                        document.body.style.removeProperty('padding-right');
                        document.body.style.removeProperty('overflow');
                    };

                    const withModal = (id, callback) => {
                        if (!id || typeof bootstrap === 'undefined') {
                            return;
                        }

                        const element = document.getElementById(id);

                        if (!element) {
                            return;
                        }

                        callback(bootstrap.Modal.getOrCreateInstance(element));
                    };

                    window.addEventListener('customers-list:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('customers-list:modal-hide', (event) => {
                        withModal(event.detail?.id, (modal) => modal.hide());
                    });

                    document.addEventListener('livewire:navigating', cleanupModalState);
                    document.addEventListener('livewire:navigated', cleanupModalState);

                    cleanupModalState();
                })();
            </script>
        @endonce
    @endpush
</div>

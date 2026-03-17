<?php

use App\Support\Admin\ManagesPaymentPlansPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Payment Plans | METKURD')]
class extends Component
{
    use ManagesPaymentPlansPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Service Plan Payments</h4>
                    <p class="text-muted mb-0">Manage subscription packs, credit allowances, monthly and yearly pricing, and activation state.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreatePlanModal">New Plan</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Plan Catalog</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['plans']) }}</h2>
                    <p class="text-muted mb-0">{{ number_format($this->topStats['active_plans']) }} active, {{ number_format($this->topStats['paid_plans']) }} paid.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Active Subscribers</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_subscribers']) }}</h2>
                    <p class="text-muted mb-0">Customers currently attached to a live service plan.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Plan Orders</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['orders']) }}</h2>
                    <p class="text-muted mb-0">{{ $this->formatCredits($this->topStats['credits']) }} credits sold through subscriptions.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Revenue</p>
                    <h2 class="mb-1">{{ $this->formatMoney($this->topStats['revenue']) }}</h2>
                    <p class="text-muted mb-0">Lifetime paid revenue generated from service plan orders.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-5">
                    <label class="form-label text-muted text-uppercase fs-12">Search</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search plan name, code, or billing interval...">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Status</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Type</label>
                    <select class="form-select" wire:model.live="typeFilter">
                        <option value="all">All plans</option>
                        <option value="paid">Paid</option>
                        <option value="free">Free</option>
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Sort</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'sort_order' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('sort_order')">
                            Priority
                            @if ($sortColumn === 'sort_order')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'active_subscribers' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('active_subscribers')">
                            Subs
                            @if ($sortColumn === 'active_subscribers')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'revenue' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('revenue')">
                            Revenue
                            @if ($sortColumn === 'revenue')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">Plan Pricing Table</h5>
                    <p class="text-muted mb-0">Update credits, pricing, and activation without leaving the table view.</p>
                </div>
                <div class="small text-muted">{{ $this->plans->total() }} plans matched the current filters.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Plan</th>
                            <th>Credits</th>
                            <th>Pricing</th>
                            <th>Adoption</th>
                            <th>Revenue</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->plans as $plan)
                            <tr wire:key="payment-plan-{{ $plan->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $plan->name }}</span>
                                        <span class="text-muted small">{{ $plan->code }} • {{ ucfirst($plan->billing_interval) }}</span>
                                        <span class="text-muted small">Priority {{ number_format((int) ($plan->sort_order ?? 0)) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCredits($plan->monthly_credits) }}</span>
                                        <span class="text-muted small">monthly credits</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        @if ($plan->is_free)
                                            <span class="fw-semibold text-success">Free</span>
                                            <span class="text-muted small">No payment required</span>
                                        @else
                                            <span class="fw-semibold">{{ $this->formatMoney($plan->price_usd_monthly) }} / month</span>
                                            <span class="text-muted small">{{ $this->formatMoney($plan->price_usd_yearly) }} / year</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format((int) ($plan->active_subscribers ?? 0)) }} active</span>
                                        <span class="text-muted small">{{ number_format((int) ($plan->paid_orders ?? 0)) }} paid orders</span>
                                        <span class="text-muted small">{{ $plan->last_order_at ? \Illuminate\Support\Carbon::parse($plan->last_order_at)->diffForHumans() : 'No paid orders yet' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatMoney($plan->revenue) }}</span>
                                        <span class="text-muted small">{{ $this->formatCredits($plan->credits_sold) }} credits sold</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" {{ $plan->is_active ? 'checked' : '' }} wire:click="togglePlanStatus({{ $plan->id }})">
                                    </div>
                                    <span class="badge {{ $plan->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $plan->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditPlanModal({{ $plan->id }})">Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeletePlan({{ $plan->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No service plans matched the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->plans->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentPlanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">{{ $editingPlanId ? 'Edit Service Plan' : 'Create Service Plan' }}</h5>
                        <p class="text-muted mb-0">Adjust credits, billing interval, and plan pricing from one form.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetPlanForm"></button>
                </div>
                <form wire:submit="savePlan">
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Code</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="pro">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="Pro">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Billing Interval</label>
                                <select class="form-select @error('billingInterval') is-invalid @enderror" wire:model.defer="billingInterval">
                                    <option value="monthly">Monthly</option>
                                    <option value="yearly">Yearly</option>
                                    <option value="lifetime">Lifetime</option>
                                </select>
                                @error('billingInterval') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Monthly Credits</label>
                                <input type="number" min="0" class="form-control @error('monthlyCredits') is-invalid @enderror" wire:model.defer="monthlyCredits">
                                @error('monthlyCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Monthly Price (USD)</label>
                                <input type="number" min="0" step="0.01" class="form-control @error('priceUsdMonthly') is-invalid @enderror" wire:model.defer="priceUsdMonthly">
                                @error('priceUsdMonthly') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Yearly Price (USD)</label>
                                <input type="number" min="0" step="0.01" class="form-control @error('priceUsdYearly') is-invalid @enderror" wire:model.defer="priceUsdYearly">
                                @error('priceUsdYearly') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sort Order</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="planIsFree" wire:model.defer="isFree">
                                    <label class="form-check-label" for="planIsFree">Free plan</label>
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="planIsActive" wire:model.defer="isActive">
                                    <label class="form-check-label" for="planIsActive">Active</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">UI Features JSON</label>
                                <textarea class="form-control font-monospace @error('uiFeaturesJson') is-invalid @enderror" rows="5" wire:model.defer="uiFeaturesJson" placeholder='{"badge":"PRO","highlight":true}'></textarea>
                                @error('uiFeaturesJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Meta JSON</label>
                                <textarea class="form-control font-monospace @error('metaJson') is-invalid @enderror" rows="5" wire:model.defer="metaJson" placeholder='{"theme":"default"}'></textarea>
                                @error('metaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetPlanForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingPlanId ? 'Save Changes' : 'Create Plan' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentPlanDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Delete <span class="fw-semibold">{{ $deletePlanLabel }}</span>? This only works for plans without subscriptions, pricing dependencies, or paid orders.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">Cancel</button>
                    <button type="button" class="btn btn-danger" wire:click="deletePlan">Delete</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__PAYMENT_PLANS_MODAL_EVENTS__) {
                        return;
                    }

                    window.__PAYMENT_PLANS_MODAL_EVENTS__ = true;

                    const modalIds = ['paymentPlanModal', 'paymentPlanDeleteModal'];

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

                    window.addEventListener('payments-plans:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('payments-plans:modal-hide', (event) => {
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

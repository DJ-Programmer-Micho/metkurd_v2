<?php

use App\Support\Admin\ManagesPaymentStoragesPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Payment Storage Plans | METKURD')]
class extends Component
{
    use ManagesPaymentStoragesPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Storage Plan Payments</h4>
                    <p class="text-muted mb-0">Configure storage pack sizes, pricing, storefront ordering, and live availability for customer storage upgrades.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreateStorageModal">New Storage Plan</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Storage Catalog</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['plans']) }}</h2>
                    <p class="text-muted mb-0">{{ number_format($this->topStats['active_plans']) }} plans are currently active.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Active Subscribers</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_subscribers']) }}</h2>
                    <p class="text-muted mb-0">Customers currently attached to an active storage plan.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Estimated Monthly Revenue</p>
                    <h2 class="mb-1">{{ $this->formatMoney($this->topStats['estimated_revenue']) }}</h2>
                    <p class="text-muted mb-0">Active subscribers multiplied by the configured storage plan price.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Largest Pack</p>
                    <h2 class="mb-1">{{ $this->maxQuotaMb ? $this->formatStorageQuota($this->maxQuotaMb) : '0 MB' }}</h2>
                    <p class="text-muted mb-0">Largest storage size in the current filtered result set.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-7">
                    <label class="form-label text-muted text-uppercase fs-12">Search</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search storage name or code...">
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
                <div class="col-xl-3 col-md-8">
                    <label class="form-label text-muted text-uppercase fs-12">Sort</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'quota_mb' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('quota_mb')">
                            Size
                            @if ($sortColumn === 'quota_mb')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'price_usd' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('price_usd')">
                            Price
                            @if ($sortColumn === 'price_usd')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'active_subscribers' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('active_subscribers')">
                            Subs
                            @if ($sortColumn === 'active_subscribers')
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
                    <h5 class="card-title mb-1">Storage Pricing Table</h5>
                    <p class="text-muted mb-0">Manage size, pricing, and activation state for storage upgrades.</p>
                </div>
                <div class="small text-muted">{{ $this->storagePlans->total() }} storage plans matched the current filters.</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Plan</th>
                            <th>Storage</th>
                            <th>Price</th>
                            <th>Subscribers</th>
                            <th>Estimated Revenue</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->storagePlans as $plan)
                            <tr wire:key="payment-storage-{{ $plan->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $plan->name }}</span>
                                        <span class="text-muted small">{{ $plan->code }}</span>
                                        <span class="text-muted small">Priority {{ number_format((int) ($plan->sort_order ?? 0)) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatStorageQuota($plan->quota_mb) }}</span>
                                        <span class="text-muted small">{{ number_format((int) ($plan->quota_mb ?? 0)) }} MB</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatMoney($plan->price_usd) }}</span>
                                        <span class="text-muted small">per storage change</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format((int) ($plan->active_subscribers ?? 0)) }} active</span>
                                        <span class="text-muted small">{{ number_format((int) ($plan->historical_assignments ?? 0)) }} total assignments</span>
                                        <span class="text-muted small">{{ $plan->last_assigned_at ? \Illuminate\Support\Carbon::parse($plan->last_assigned_at)->diffForHumans() : 'No assignments yet' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatMoney($plan->estimated_revenue) }}</span>
                                        <span class="text-muted small">active price exposure</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" {{ $plan->is_active ? 'checked' : '' }} wire:click="toggleStorageStatus({{ $plan->id }})">
                                    </div>
                                    <span class="badge {{ $plan->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $plan->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditStorageModal({{ $plan->id }})">Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeleteStorage({{ $plan->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No storage plans matched the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->storagePlans->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentStorageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">{{ $editingStorageId ? 'Edit Storage Plan' : 'Create Storage Plan' }}</h5>
                        <p class="text-muted mb-0">Set pack size, pricing, and storefront order for storage upgrades.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetStorageForm"></button>
                </div>
                <form wire:submit="saveStoragePlan">
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Code</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="pro-5120">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="Pro (5GB)">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quota (MB)</label>
                                <input type="number" min="1" class="form-control @error('quotaMb') is-invalid @enderror" wire:model.defer="quotaMb">
                                @error('quotaMb') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Price (USD)</label>
                                <input type="number" min="0" step="0.01" class="form-control @error('priceUsd') is-invalid @enderror" wire:model.defer="priceUsd">
                                @error('priceUsd') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sort Order</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="storageIsActive" wire:model.defer="isActive">
                                    <label class="form-check-label" for="storageIsActive">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetStorageForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingStorageId ? 'Save Changes' : 'Create Storage Plan' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentStorageDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Storage Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Delete <span class="fw-semibold">{{ $deleteStorageLabel }}</span>? This only works if the plan has no customer subscriptions.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">Cancel</button>
                    <button type="button" class="btn btn-danger" wire:click="deleteStoragePlan">Delete</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__PAYMENT_STORAGE_MODAL_EVENTS__) {
                        return;
                    }

                    window.__PAYMENT_STORAGE_MODAL_EVENTS__ = true;

                    const modalIds = ['paymentStorageModal', 'paymentStorageDeleteModal'];

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

                    window.addEventListener('payments-storage:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('payments-storage:modal-hide', (event) => {
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

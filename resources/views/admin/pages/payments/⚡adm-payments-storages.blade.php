<?php

use App\Support\Admin\ManagesPaymentStoragesPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesPaymentStoragesPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Payment Storage Plans') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-capability-notice :capabilities="['admin.catalog']" />
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Storage Plan Payments') }}</h4>
                    <p class="text-muted mb-0">{{ __('Configure storage pack sizes, pricing, storefront ordering, and live availability for customer storage upgrades.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <select class="form-select" wire:model.live="displayCurrencyCode" style="min-width: 180px;">
                        @foreach ($this->displayCurrencyOptions as $currencyCode => $currencyLabel)
                            <option value="{{ $currencyCode }}">{{ $currencyLabel }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreateStorageModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('New Storage Plan') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Storage Catalog') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['plans']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':count plans are currently active.', ['count' => number_format($this->topStats['active_plans'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Active Subscribers') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_subscribers']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Customers currently attached to an active storage plan.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Estimated Monthly Revenue') }}</p>
                    <h2 class="mb-1">{{ $this->formatCanonicalMoneyWithOptionalDisplay($this->topStats['estimated_revenue']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Active subscribers multiplied by the configured storage plan price, stored canonically in IQD.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Largest Pack') }}</p>
                    <h2 class="mb-1">{{ $this->maxQuotaMb ? $this->formatStorageQuota($this->maxQuotaMb) : __('0 MB') }}</h2>
                    <p class="text-muted mb-0">{{ __('Largest storage size in the current filtered result set.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-7">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-storages-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search storage name or code...') }}" id="admin-field-adm-payments-storages-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-storages-2">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-payments-storages-2">
                        <option value="all">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-xl-3 col-md-8">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Sort') }}</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'quota_mb' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('quota_mb')">
                            {{ __('Size') }}
                            @if ($sortColumn === 'quota_mb')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'price_iqd' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('price_iqd')">
                            {{ __('Price') }}
                            @if ($sortColumn === 'price_iqd')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'active_subscribers' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('active_subscribers')">
                            {{ __('Subs') }}
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
                    <h5 class="card-title mb-1">{{ __('Storage Pricing Table') }}</h5>
                    <p class="text-muted mb-0">{{ __('Manage size, pricing, and activation state for storage upgrades.') }}</p>
                </div>
                <div class="small text-muted">{{ __(':count storage plans matched the current filters.', ['count' => $this->storagePlans->total()]) }}</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Storage') }}</th>
                            <th>{{ __('Price') }}</th>
                            <th>{{ __('Subscribers') }}</th>
                            <th>{{ __('Estimated Revenue') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->storagePlans as $plan)
                            <tr wire:key="payment-storage-{{ $plan->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $plan->name }}</span>
                                        <span class="text-muted small">{{ $plan->code }}</span>
                                        @php
                                            $billingCyclesLabel = collect($plan->billingIntervals())
                                                ->map(fn (string $cycle) => __($cycle === 'yearly' ? 'Yearly' : 'Monthly'))
                                                ->join(', ');
                                        @endphp
                                        <span class="text-muted small">{{ __('Billing cycles: :value', ['value' => $billingCyclesLabel]) }}</span>
                                        <span class="text-muted small">{{ __('Payment mode: :value', ['value' => __($plan->checkoutPaymentMode()->label())]) }}</span>
                                        <span class="text-muted small">{{ __('Priority :value', ['value' => number_format((int) ($plan->sort_order ?? 0))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatStorageQuota($plan->quota_mb) }}</span>
                                        <span class="text-muted small">{{ __(':count MB', ['count' => number_format((int) ($plan->quota_mb ?? 0))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCanonicalPrimary($plan->price_iqd_effective) }}</span>
                                        @if($this->formatOptionalDisplayMoney($plan->price_iqd_effective))
                                            <span class="text-muted small">{{ $this->formatOptionalDisplayMoney($plan->price_iqd_effective) }}</span>
                                        @else
                                            <span class="text-muted small">{{ __('per storage change') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __(':count active', ['count' => number_format((int) ($plan->active_subscribers ?? 0))]) }}</span>
                                        <span class="text-muted small">{{ __(':count total assignments', ['count' => number_format((int) ($plan->historical_assignments ?? 0))]) }}</span>
                                        <span class="text-muted small">{{ $plan->last_assigned_at ? \Illuminate\Support\Carbon::parse($plan->last_assigned_at)->diffForHumans() : __('No assignments yet') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCanonicalMoneyWithOptionalDisplay($plan->estimated_revenue) }}</span>
                                        <span class="text-muted small">{{ __('active price exposure') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" {{ $plan->is_active ? 'checked' : '' }} wire:click="toggleStorageStatus({{ $plan->id }})">
                                    </div>
                                    <span class="badge {{ $plan->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $plan->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditStorageModal({{ $plan->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeleteStorage({{ $plan->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">{{ __('No storage plans matched the current filters.') }}</td>
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
                        <h5 class="modal-title mb-1">{{ $editingStorageId ? __('Edit Storage Plan') : __('Create Storage Plan') }}</h5>
                        <p class="text-muted mb-0">{{ __('Set pack size, pricing, and storefront order for storage upgrades.') }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetStorageForm"></button>
                </div>
                <form data-admin-method="saveStoragePlan" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.catalog_effect') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-storages-3">{{ __('Code') }}</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="{{ __('pro-5120') }}" data-admin-review id="admin-field-adm-payments-storages-3">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-storages-4">{{ __('Name') }}</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="{{ __('Pro (5GB)') }}" data-admin-review id="admin-field-adm-payments-storages-4">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-storages-5">{{ __('Payment Mode') }}</label>
                                <select class="form-select @error('paymentMode') is-invalid @enderror" wire:model.defer="paymentMode" id="admin-field-adm-payments-storages-5">
                                    <option value="one_time">{{ __('Manual Payment') }}</option>
                                    <option value="recurring">{{ __('Auto Renewal') }}</option>
                                </select>
                                <div class="form-text">{{ __('Manual Payment uses gateway checkout with no auto-renew.') }}</div>
                                @error('paymentMode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ __('Available Billing Cycles') }}</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="storageBillingCycleMonthly" value="monthly" wire:model.defer="billingIntervals">
                                    <label class="form-check-label" for="storageBillingCycleMonthly">{{ __('Monthly') }}</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="storageBillingCycleYearly" value="yearly" wire:model.defer="billingIntervals">
                                    <label class="form-check-label" for="storageBillingCycleYearly">{{ __('Yearly') }}</label>
                                </div>
                                <div class="form-text">{{ __('Select where this plan should appear on the customer pricing page.') }}</div>
                                @error('billingIntervals') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                @if($errors->has('billingIntervals.*'))
                                    <div class="text-danger small mt-1">{{ $errors->first('billingIntervals.*') }}</div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-storages-6">{{ __('Quota (MB)') }}</label>
                                <input type="number" min="1" class="form-control @error('quotaMb') is-invalid @enderror" wire:model.defer="quotaMb" id="admin-field-adm-payments-storages-6">
                                @error('quotaMb') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-storages-7">{{ __('Price (IQD)') }}</label>
                                <input type="number" min="0" step="250" class="form-control @error('priceIqd') is-invalid @enderror" wire:model.live.debounce.200ms="priceIqd" id="admin-field-adm-payments-storages-7">
                                @error('priceIqd') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-storages-8">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder" id="admin-field-adm-payments-storages-8">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="border rounded-3 p-3 bg-light-subtle">
                                    <div class="fw-semibold mb-2">{{ __('Live currency preview from IQD base') }}</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($this->pricePreviewRows($priceIqd) as $preview)
                                            <span class="badge bg-body text-body border">
                                                {{ $preview['code'] }}: {{ $preview['formatted'] }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="storageIsActive" wire:model.defer="isActive">
                                    <label class="form-check-label" for="storageIsActive">{{ __('Active') }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetStorageForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingStorageId ? __('Save Changes') : __('Create Storage Plan') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentStorageDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Delete Storage Plan') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deleteStorageLabel }}</span>? {{ __('This only works if the plan has no customer subscriptions.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-admin-target="{{ $deleteStorageLabel }}" data-admin-method="deleteStoragePlan" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.delete_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
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

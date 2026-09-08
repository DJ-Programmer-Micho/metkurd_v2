<?php

use App\Support\Admin\ManagesPaymentAddonsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesPaymentAddonsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Payment Addons') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Credit Product Payments') }}</h4>
                    <p class="text-muted mb-0">{{ __('Create and tune one-time credit packs, pack sizes, pricing, and storefront ordering.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <select class="form-select" wire:model.live="displayCurrencyCode" style="min-width: 180px;">
                        @foreach ($this->displayCurrencyOptions as $currencyCode => $currencyLabel)
                            <option value="{{ $currencyCode }}">{{ $currencyLabel }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreateProductModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('New Credit Product') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Product Catalog') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['products']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':count products are currently live.', ['count' => number_format($this->topStats['active_products'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Paid Orders') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['orders']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Completed add-on purchases across all credit products.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Credits Sold') }}</p>
                    <h2 class="mb-1">{{ $this->formatCredits($this->topStats['credits']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Credits delivered through paid add-on packs.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Revenue') }}</p>
                    <h2 class="mb-1">{{ $this->formatCanonicalMoneyWithOptionalDisplay($this->topStats['revenue']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Lifetime revenue from credit product purchases, stored canonically in IQD.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-6">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-addons-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search add-on name or code...') }}" id="admin-field-adm-payments-addons-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-addons-2">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-payments-addons-2">
                        <option value="all">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-xl-4 col-md-8">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Sort') }}</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'sort_order' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('sort_order')">
                            {{ __('Priority') }}
                            @if ($sortColumn === 'sort_order')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'credits_amount' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('credits_amount')">
                            {{ __('Credits') }}
                            @if ($sortColumn === 'credits_amount')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'revenue' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('revenue')">
                            {{ __('Revenue') }}
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
                    <h5 class="card-title mb-1">{{ __('Credit Product Table') }}</h5>
                    <p class="text-muted mb-0">{{ __('Edit pack sizing and pricing without leaving the payments workspace.') }}</p>
                </div>
                <div class="small text-muted">{{ __(':count products matched the current filters.', ['count' => $this->products->total()]) }}</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('Credits') }}</th>
                            <th>{{ __('Price') }}</th>
                            <th>{{ __('Orders') }}</th>
                            <th>{{ __('Revenue') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->products as $product)
                            <tr wire:key="payment-addon-{{ $product->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $product->name }}</span>
                                        <span class="text-muted small">{{ $product->code }}</span>
                                        <span class="text-muted small">{{ __('Priority :value', ['value' => number_format((int) ($product->sort_order ?? 0))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCredits($product->credits_amount) }}</span>
                                        <span class="text-muted small">{{ __('credits per purchase') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCanonicalPrimary($product->price_iqd_effective) }}</span>
                                        @if($this->formatOptionalDisplayMoney($product->price_iqd_effective))
                                            <span class="text-muted small">{{ $this->formatOptionalDisplayMoney($product->price_iqd_effective) }}</span>
                                        @else
                                            <span class="text-muted small">{{ __('one-time payment') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format((int) ($product->paid_orders ?? 0)) }}</span>
                                        <span class="text-muted small">{{ $product->last_order_at ? \Illuminate\Support\Carbon::parse($product->last_order_at)->diffForHumans() : __('No paid orders yet') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCanonicalMoneyWithOptionalDisplay($product->revenue) }}</span>
                                        <span class="text-muted small">{{ __(':credits credits sold', ['credits' => $this->formatCredits($product->credits_sold)]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" {{ $product->is_active ? 'checked' : '' }} wire:click="toggleProductStatus({{ $product->id }})">
                                    </div>
                                    <span class="badge {{ $product->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $product->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditProductModal({{ $product->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeleteProduct({{ $product->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">{{ __('No credit products matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->products->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentAddonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">{{ $editingProductId ? __('Edit Credit Product') : __('Create Credit Product') }}</h5>
                        <p class="text-muted mb-0">{{ __('Manage one-time credit pack sizing and storefront pricing.') }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetProductForm"></button>
                </div>
                <form data-admin-method="saveProduct" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.catalog_effect') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-addons-3">{{ __('Code') }}</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="{{ __('addon_50000') }}" data-admin-review id="admin-field-adm-payments-addons-3">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-addons-4">{{ __('Name') }}</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="{{ __('Add-on 50,000 Credits') }}" data-admin-review id="admin-field-adm-payments-addons-4">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-addons-5">{{ __('Credits Amount') }}</label>
                                <input type="number" min="0" class="form-control @error('creditsAmount') is-invalid @enderror" wire:model.defer="creditsAmount" id="admin-field-adm-payments-addons-5">
                                @error('creditsAmount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-addons-6">{{ __('Price (IQD)') }}</label>
                                <input type="number" min="0" step="250" class="form-control @error('priceIqd') is-invalid @enderror" wire:model.live.debounce.200ms="priceIqd" id="admin-field-adm-payments-addons-6">
                                @error('priceIqd') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-addons-7">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder" id="admin-field-adm-payments-addons-7">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="addonIsActive" wire:model.defer="isActive">
                                    <label class="form-check-label" for="addonIsActive">{{ __('Active') }}</label>
                                </div>
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
                                <label class="form-label">{{ __('Meta JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea class="form-control font-monospace @error('metaJson') is-invalid @enderror" rows="5" wire:model.defer="metaJson" placeholder='{{ __("{\"badge\":\"Popular\"}") }}' dir="ltr"></textarea></details>
                                @error('metaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetProductForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingProductId ? __('Save Changes') : __('Create Product') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentAddonDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Delete Credit Product') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deleteProductLabel }}</span>? {{ __('This only works if the product has no paid orders.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-admin-target="{{ $deleteProductLabel }}" data-admin-method="deleteProduct" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.delete_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__PAYMENT_ADDONS_MODAL_EVENTS__) {
                        return;
                    }

                    window.__PAYMENT_ADDONS_MODAL_EVENTS__ = true;

                    const modalIds = ['paymentAddonModal', 'paymentAddonDeleteModal'];

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

                    window.addEventListener('payments-addons:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('payments-addons:modal-hide', (event) => {
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

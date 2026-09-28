<?php

use App\Support\Admin\ManagesPaymentMethodsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesPaymentMethodsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('admin_shell.methods') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-capability-notice :capabilities="['admin.catalog']" />
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('admin_shell.methods') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage checkout visibility, order, supported purchase types, currencies, and fee rules from one central catalog.') }}</p>
                </div>
                <div class="page-title-right d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreateMethodModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('New Payment Method') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        {{ __('Provider credentials stay in .env and config/payments.php. This page manages non-secret checkout behavior, labels, order, visibility, currencies, and fee rules.') }}
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Methods') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['methods']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Configured payment methods in the central catalog.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Active') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_methods']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Methods allowed to participate in checkout rules.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Visible') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['visible_methods']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Methods currently visible to customers in checkout screens.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Used In Payments') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['used_methods']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Distinct payment method codes already used in payment intents.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-7">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-methods-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search method code, name, or driver...') }}" id="admin-field-adm-payments-methods-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-5">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-methods-2">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-payments-methods-2">
                        <option value="all">{{ __('All') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                        <option value="visible">{{ __('Visible') }}</option>
                        <option value="hidden">{{ __('Hidden') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">{{ __('Method Table') }}</h5>
                    <p class="text-muted mb-0">{{ __('Checkout uses this table as the source of truth for visible customer payment options.') }}</p>
                </div>
                <div class="small text-muted">{{ __(':count methods matched the current filters.', ['count' => $this->methods->total()]) }}</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Method') }}</th>
                            <th>{{ __('Capabilities') }}</th>
                            <th>{{ __('Coverage') }}</th>
                            <th>{{ __('Runtime') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->methods as $method)
                            <tr wire:key="payment-method-{{ $method->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $method->name }}</span>
                                        <span class="text-muted small">{{ $method->code }} / {{ $method->driver }}</span>
                                        <span class="text-muted small">{{ __('Sort order: :value', ['value' => number_format((int) $method->sort_order)]) }}</span>
                                        @if ($method->description)
                                            <span class="text-muted small">{{ $method->description }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        @if($method->supports_redirect)<span class="badge bg-light text-body">{{ __('Redirect') }}</span>@endif
                                        @if($method->supports_qr)<span class="badge bg-light text-body">{{ __('QR') }}</span>@endif
                                        @if($method->supports_webhooks)<span class="badge bg-light text-body">{{ __('Webhook') }}</span>@endif
                                        @if($method->supports_refunds)<span class="badge bg-light text-body">{{ __('Refunds') }}</span>@endif
                                        @if($method->supports_recurring)<span class="badge bg-light text-body">{{ __('Recurring') }}</span>@endif
                                        @if(! $method->supports_redirect && ! $method->supports_qr && ! $method->supports_webhooks && ! $method->supports_refunds && ! $method->supports_recurring)
                                            <span class="text-muted small">{{ __('No extra flags') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="text-muted small">{{ __('Currencies: :value', ['value' => implode(', ', $method->supported_currencies ?? ['IQD'])]) }}</span>
                                        <span class="text-muted small">{{ __('Purchase types: :value', ['value' => implode(', ', $method->supported_purchase_types ?? []) ?: __('All')]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge {{ $this->driverEnabledBadge($method) ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                            {{ $this->driverEnabledBadge($method) ? __('Driver enabled') : __('Driver disabled') }}
                                        </span>
                                        <span class="badge {{ $this->configurationReadyBadge($method) ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning' }}">
                                            {{ $this->configurationReadyBadge($method) ? __('Config ready') : __('Config missing') }}
                                        </span>
                                        <span class="badge {{ $this->checkoutReadyBadge($method) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                            {{ $this->checkoutReadyBadge($method) ? __('Checkout ready') : __('Checkout not implemented') }}
                                        </span>
                                        @if($this->configurationIssuesForMethod($method) !== [])
                                            <div class="small text-muted">
                                                {{ implode(' · ', $this->configurationIssuesForMethod($method)) }}
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="d-flex gap-2 align-items-center">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" aria-label="{{ __('Status') }}" {{ $method->is_active ? 'checked' : '' }} wire:click="toggleMethodStatus({{ $method->id }})">
                                            </div>
                                            <span class="badge {{ $method->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                                {{ $method->is_active ? __('Active') : __('Inactive') }}
                                            </span>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" aria-label="{{ __('Visibility') }}" {{ $method->is_visible ? 'checked' : '' }} wire:click="toggleMethodVisibility({{ $method->id }})">
                                            </div>
                                            <span class="badge {{ $method->is_visible ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' }}">
                                                {{ $method->is_visible ? __('Visible') : __('Hidden') }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditMethodModal({{ $method->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeleteMethod({{ $method->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">{{ __('No payment methods matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->methods->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentMethodModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">{{ $editingMethodId ? __('Edit Payment Method') : __('Create Payment Method') }}</h5>
                        <p class="text-muted mb-0">{{ __('Non-secret payment method behavior lives here. Provider secrets still stay in environment config.') }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetMethodForm"></button>
                </div>
                <form data-admin-method="saveMethod" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.catalog_effect') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                    @csrf
                    <div class="modal-body">
                        <x-admin-validation-summary />
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-methods-3">{{ __('Code') }}</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" @disabled($editingMethodId !== null) placeholder="{{ __('fib') }}" data-admin-review id="admin-field-adm-payments-methods-3">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-methods-4">{{ __('Driver') }}</label>
                                <select class="form-select @error('driver') is-invalid @enderror" wire:model.defer="driver" @disabled($editingMethodId !== null) id="admin-field-adm-payments-methods-4">
                                    @foreach ($this->driverOptions as $driverCode => $driverLabel)
                                        <option value="{{ $driverCode }}">{{ $driverLabel }}</option>
                                    @endforeach
                                </select>
                                @error('driver') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-methods-5">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder" id="admin-field-adm-payments-methods-5">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-methods-6">{{ __('Name') }}</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" data-admin-review id="admin-field-adm-payments-methods-6">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-methods-7">{{ __('Icon') }}</label>
                                <input type="text" class="form-control @error('icon') is-invalid @enderror" wire:model.defer="icon" placeholder="{{ __('ri-bank-card-line') }}" id="admin-field-adm-payments-methods-7">
                                @error('icon') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="admin-field-adm-payments-methods-8">{{ __('Description') }}</label>
                                <textarea class="form-control @error('description') is-invalid @enderror" rows="2" wire:model.defer="description" id="admin-field-adm-payments-methods-8" dir="auto"></textarea>
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-methods-9">{{ __('Supported Currencies') }}</label>
                                <input type="text" class="form-control @error('supportedCurrenciesText') is-invalid @enderror" wire:model.defer="supportedCurrenciesText" placeholder="{{ __('IQD, USD') }}" id="admin-field-adm-payments-methods-9">
                                @error('supportedCurrenciesText') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ __('Comma-separated ISO currency codes. Leave empty to allow all.') }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block">{{ __('Supported Purchase Types') }}</label>
                                <div class="d-flex flex-wrap gap-3 border rounded-3 p-3">
                                    @foreach ($this->purchaseTypeOptions as $purchaseTypeCode => $purchaseTypeLabel)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="pm-{{ $purchaseTypeCode }}" value="{{ $purchaseTypeCode }}" wire:model.defer="supportedPurchaseTypes">
                                            <label class="form-check-label" for="pm-{{ $purchaseTypeCode }}">{{ $purchaseTypeLabel }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label d-block">{{ __('Capabilities') }}</label>
                                <div class="d-flex flex-wrap gap-4 border rounded-3 p-3">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmRecurring" wire:model.defer="supportsRecurring"><label class="form-check-label" for="pmRecurring">{{ __('Recurring') }}</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmRefunds" wire:model.defer="supportsRefunds"><label class="form-check-label" for="pmRefunds">{{ __('Refunds') }}</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmWebhooks" wire:model.defer="supportsWebhooks"><label class="form-check-label" for="pmWebhooks">{{ __('Webhooks') }}</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmRedirect" wire:model.defer="supportsRedirect"><label class="form-check-label" for="pmRedirect">{{ __('Redirect') }}</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmQr" wire:model.defer="supportsQr"><label class="form-check-label" for="pmQr">{{ __('QR') }}</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmActive" wire:model.defer="isActive"><label class="form-check-label" for="pmActive">{{ __('Active') }}</label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="pmVisible" wire:model.defer="isVisible"><label class="form-check-label" for="pmVisible">{{ __('Visible in checkout') }}</label></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Settings JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea class="form-control font-monospace @error('settingsJson') is-invalid @enderror" rows="6" wire:model.defer="settingsJson" dir="ltr"></textarea></details>
                                @error('settingsJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Fee Config JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea class="form-control font-monospace @error('feeConfigJson') is-invalid @enderror" rows="6" wire:model.defer="feeConfigJson" dir="ltr"></textarea></details>
                                @error('feeConfigJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Meta JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea class="form-control font-monospace @error('metaJson') is-invalid @enderror" rows="6" wire:model.defer="metaJson" dir="ltr"></textarea></details>
                                @error('metaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetMethodForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingMethodId ? __('Save Changes') : __('Create Method') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentMethodDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Delete Payment Method') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                        <x-admin-validation-summary />
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deleteMethodLabel }}</span>? {{ __('Only unused methods should be deleted. Otherwise deactivate or hide them.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-admin-target="{{ $deleteMethodLabel }}" data-admin-method="deleteMethod" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.delete_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>


</div>

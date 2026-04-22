<?php

use App\Support\Admin\ManagesPaymentCouponsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesPaymentCouponsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Payment Coupons') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Checkout Coupons') }}</h4>
                    <p class="text-muted mb-0">{{ __('Create, limit, schedule, and review coupon usage across subscriptions, storage, and one-time add-on payments.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <select class="form-select" wire:model.live="displayCurrencyCode" style="min-width: 180px;">
                        @foreach ($this->displayCurrencyOptions as $currencyCode => $currencyLabel)
                            <option value="{{ $currencyCode }}">{{ $currencyLabel }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreateCouponModal">{{ __('New Coupon') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">{{ __('Recurring FIB limitation') }}</div>
        <div>{{ __('The current FIB recurring API supports a fixed subscription amount. Coupons with first-cycle or first-N-cycle discount duration are stored and managed here, but live FIB recurring checkout currently only accepts forever-priced recurring discounts.') }}</div>
        <div class="small mt-1">{{ __('Coupon date windows on this page are entered and displayed in :timezone.', ['timezone' => config('app.timezone')]) }}</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Coupons') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['coupons']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':active active, :private private/internal.', ['active' => number_format($this->topStats['active_coupons']), 'private' => number_format($this->topStats['private_coupons'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Redemptions') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['consumed_redemptions']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':total total records, :live still in checkout flow.', ['total' => number_format($this->topStats['total_redemptions']), 'live' => number_format($this->topStats['in_checkout'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Discount Value') }}</p>
                    <h2 class="mb-1">{{ $this->formatCanonicalMoneyWithOptionalDisplay($this->topStats['discounted_value_iqd']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Total consumed discount value across successful redemptions.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Policy') }}</p>
                    <h2 class="mb-1">{{ __('Server-Side') }}</h2>
                    <p class="text-muted mb-0">{{ __('Eligibility, limits, and final amounts are validated before creating the FIB checkout.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-5">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search coupon code, name, or notes...') }}">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Target') }}</label>
                    <select class="form-select" wire:model.live="targetFilter">
                        @foreach ($this->targetOptions() as $targetCode => $targetLabel)
                            <option value="{{ $targetCode }}">{{ $targetLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Sort') }}</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'created_at' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('created_at')">
                            {{ __('Newest') }}
                            @if ($sortColumn === 'created_at')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'used_count' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('used_count')">
                            {{ __('Usage') }}
                            @if ($sortColumn === 'used_count')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'code' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('code')">
                            {{ __('Code') }}
                            @if ($sortColumn === 'code')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">{{ __('Coupon Catalog') }}</h5>
                    <p class="text-muted mb-0">{{ __('Review discount scope, duration, restrictions, usage limits, and current availability from one table.') }}</p>
                </div>
                <div class="small text-muted">{{ __(':count coupons matched the current filters.', ['count' => $this->coupons->total()]) }}</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Coupon') }}</th>
                            <th>{{ __('Discount') }}</th>
                            <th>{{ __('Scope') }}</th>
                            <th>{{ __('Restrictions') }}</th>
                            <th>{{ __('Usage') }}</th>
                            <th>{{ __('Window') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->coupons as $coupon)
                            <tr wire:key="payment-coupon-{{ $coupon->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $coupon->code }}</span>
                                        <span class="text-muted small">{{ $coupon->name }}</span>
                                        @if ($coupon->description)
                                            <span class="text-muted small">{{ $coupon->description }}</span>
                                        @endif
                                        <div class="d-flex flex-wrap gap-1 mt-2">
                                            <span class="badge bg-body text-body border">{{ $coupon->is_public ? __('Public') : __('Private') }}</span>
                                            @if ($coupon->is_stackable)
                                                <span class="badge bg-info-subtle text-info">{{ __('Stackable') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->describeCouponDiscount($coupon) }}</span>
                                        <span class="text-muted small">{{ $this->describeCouponDuration($coupon) }}</span>
                                        <span class="text-muted small">{{ $this->targetOptions()[$coupon->target_type?->value ?? 'all'] ?? __('All Checkout Types') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->targetOptions()[$coupon->target_type?->value ?? 'all'] ?? __('All Checkout Types') }}</span>
                                        <span class="text-muted small">{{ __('Currency: :currency', ['currency' => strtoupper((string) ($coupon->currency ?? 'IQD'))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-muted small" style="max-width: 320px;">
                                        {{ $this->describeCouponRestrictions($coupon) }}
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">
                                            {{ number_format((int) ($coupon->used_count ?? 0)) }}
                                            @if ($coupon->max_total_uses)
                                                / {{ number_format((int) $coupon->max_total_uses) }}
                                            @else
                                                / {{ __('Unlimited') }}
                                            @endif
                                        </span>
                                        <span class="text-muted small">{{ __('Consumed: :count', ['count' => number_format((int) ($coupon->consumed_redemptions_count ?? 0))]) }}</span>
                                        <span class="text-muted small">{{ __('In checkout: :count', ['count' => number_format((int) ($coupon->live_redemptions_count ?? 0))]) }}</span>
                                        <span class="text-muted small">
                                            {{ __('Per customer: :count', ['count' => $coupon->max_uses_per_customer ? number_format((int) $coupon->max_uses_per_customer) : __('Unlimited')]) }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-muted small" style="max-width: 220px;">
                                        {{ $this->describeCouponWindow($coupon) }}
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" {{ $coupon->is_active ? 'checked' : '' }} wire:click="toggleCouponStatus({{ $coupon->id }})">
                                    </div>
                                    <span class="badge {{ $coupon->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $coupon->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditCouponModal({{ $coupon->id }})">{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeleteCoupon({{ $coupon->id }})">{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">{{ __('No coupons matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->coupons->onEachSide(1)->links() }}
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">{{ __('Recent Redemptions') }}</h5>
                    <p class="text-muted mb-0">{{ __('Track recent coupon usage across checkout reservations, successful payments, and released attempts.') }}</p>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Coupon') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Purchase') }}</th>
                            <th>{{ __('Amounts') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->recentRedemptions as $redemption)
                            @php
                                $purchaseLabel = match ($redemption->purchase_type?->value) {
                                    'plan_subscription' => __('Plan Subscription'),
                                    'storage_subscription' => __('Storage Subscription'),
                                    default => __('Add-on Payment'),
                                };
                            @endphp
                            <tr wire:key="coupon-redemption-{{ $redemption->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $redemption->coupon_code }}</span>
                                        <span class="text-muted small">{{ $redemption->coupon?->name ?? __('Coupon') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $redemption->customer?->username ?? __('Customer') }}</span>
                                        <span class="text-muted small">{{ $redemption->customer?->email ?? __('Unknown') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $purchaseLabel }}</span>
                                        <span class="text-muted small">{{ $redemption->item_code ?: __('No item code') }}</span>
                                        @if ($redemption->billing_cycle)
                                            <span class="text-muted small">{{ ucfirst((string) $redemption->billing_cycle) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCanonicalMoneyWithOptionalDisplay($redemption->final_amount_iqd) }}</span>
                                        <span class="text-muted small">{{ __('Original: :amount', ['amount' => $this->formatCanonicalMoneyWithOptionalDisplay($redemption->original_amount_iqd)]) }}</span>
                                        <span class="text-success small">{{ __('Discount: -:amount', ['amount' => $this->formatCanonicalMoneyWithOptionalDisplay($redemption->discount_amount_iqd)]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-body text-body border text-uppercase">{{ $redemption->status?->value ?? __('unknown') }}</span>
                                </td>
                                <td>
                                    <div class="text-muted small">{{ $redemption->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">{{ __('No coupon redemptions recorded yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentCouponModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">{{ $editingCouponId ? __('Edit Coupon') : __('Create Coupon') }}</h5>
                        <p class="text-muted mb-0">{{ __('Define discount value, checkout scope, duration, limits, and activation windows in one place.') }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetCouponForm"></button>
                </div>
                <form wire:submit="saveCoupon">
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Coupon Code') }}</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="{{ __('WELCOME50') }}">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Name') }}</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="{{ __('Welcome Offer') }}">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Target Type') }}</label>
                                <select class="form-select @error('targetType') is-invalid @enderror" wire:model.defer="targetType">
                                    @foreach ($this->targetOptions() as $targetCode => $targetLabel)
                                        <option value="{{ $targetCode }}">{{ $targetLabel }}</option>
                                    @endforeach
                                </select>
                                @error('targetType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">{{ __('Description') }}</label>
                                <textarea class="form-control @error('description') is-invalid @enderror" rows="2" wire:model.defer="description" placeholder="{{ __('Optional internal or customer-facing description') }}"></textarea>
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">{{ __('Discount Type') }}</label>
                                <select class="form-select @error('discountType') is-invalid @enderror" wire:model.defer="discountType">
                                    @foreach ($this->discountTypeOptions() as $discountCode => $discountLabel)
                                        <option value="{{ $discountCode }}">{{ $discountLabel }}</option>
                                    @endforeach
                                </select>
                                @error('discountType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Discount Value') }}</label>
                                <input type="number" min="0.01" step="0.01" class="form-control @error('discountValue') is-invalid @enderror" wire:model.defer="discountValue">
                                @error('discountValue') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Duration') }}</label>
                                <select class="form-select @error('durationType') is-invalid @enderror" wire:model.live="durationType">
                                    @foreach ($this->durationOptions() as $durationCode => $durationLabel)
                                        <option value="{{ $durationCode }}">{{ $durationLabel }}</option>
                                    @endforeach
                                </select>
                                @error('durationType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Duration Cycles') }}</label>
                                <input type="number" min="1" class="form-control @error('durationCycles') is-invalid @enderror" wire:model.defer="durationCycles" @disabled($durationType !== 'first_n_cycles')>
                                <div class="form-text">{{ __('Used only for first N cycles.') }}</div>
                                @error('durationCycles') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">{{ __('Allowed Item Codes') }}</label>
                                <input type="text" class="form-control @error('appliesToCodesCsv') is-invalid @enderror" wire:model.defer="appliesToCodesCsv" placeholder="{{ __('student, pro, premium-10240') }}">
                                <div class="form-text">{{ __('Comma-separated plan, storage, or add-on codes. Leave blank for all eligible items.') }}</div>
                                @error('appliesToCodesCsv') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Allowed Billing Cycles') }}</label>
                                <input type="text" class="form-control @error('appliesToBillingCyclesCsv') is-invalid @enderror" wire:model.defer="appliesToBillingCyclesCsv" placeholder="{{ __('monthly, yearly') }}">
                                <div class="form-text">{{ __('Allowed values: monthly, yearly, hourly.') }}</div>
                                @error('appliesToBillingCyclesCsv') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Minimum Amount (IQD)') }}</label>
                                <input type="number" min="1" step="250" class="form-control @error('minimumAmountIqd') is-invalid @enderror" wire:model.defer="minimumAmountIqd">
                                @error('minimumAmountIqd') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">{{ __('Max Total Uses') }}</label>
                                <input type="number" min="1" class="form-control @error('maxTotalUses') is-invalid @enderror" wire:model.defer="maxTotalUses">
                                @error('maxTotalUses') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Max Uses Per Customer') }}</label>
                                <input type="number" min="1" class="form-control @error('maxUsesPerCustomer') is-invalid @enderror" wire:model.defer="maxUsesPerCustomer">
                                @error('maxUsesPerCustomer') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Starts At') }}</label>
                                <input type="datetime-local" class="form-control @error('startsAtLocal') is-invalid @enderror" wire:model.defer="startsAtLocal">
                                @error('startsAtLocal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Ends At') }}</label>
                                <input type="datetime-local" class="form-control @error('endsAtLocal') is-invalid @enderror" wire:model.defer="endsAtLocal">
                                @error('endsAtLocal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" wire:model.defer="isActive" id="couponIsActive">
                                    <label class="form-check-label" for="couponIsActive">{{ __('Active') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" wire:model.defer="isPublic" id="couponIsPublic">
                                    <label class="form-check-label" for="couponIsPublic">{{ __('Public') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" wire:model.defer="isStackable" id="couponIsStackable">
                                    <label class="form-check-label" for="couponIsStackable">{{ __('Stackable') }}</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" wire:model.defer="firstTimeSubscribersOnly" id="couponFirstTimeOnly">
                                    <label class="form-check-label" for="couponFirstTimeOnly">{{ __('First-time plan subscribers only') }}</label>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">{{ __('Currency') }}</label>
                                <input type="text" class="form-control @error('currency') is-invalid @enderror" wire:model.defer="currency" maxlength="3">
                                @error('currency') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-9">
                                <label class="form-label">{{ __('Metadata JSON') }}</label>
                                <textarea class="form-control @error('metadataJson') is-invalid @enderror" rows="4" wire:model.defer="metadataJson" placeholder='{"notes":"Internal campaign"}'></textarea>
                                @error('metadataJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetCouponForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingCouponId ? __('Save Changes') : __('Create Coupon') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentCouponDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Delete Coupon') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">{{ __('Delete coupon :code? This is only allowed when no redemption history exists.', ['code' => $deleteCouponLabel ?: '']) }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" wire:click="deleteCoupon">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__PAYMENT_COUPONS_MODAL_EVENTS__) {
                        return;
                    }

                    window.__PAYMENT_COUPONS_MODAL_EVENTS__ = true;

                    const modalIds = ['paymentCouponModal', 'paymentCouponDeleteModal'];

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

                    window.addEventListener('payments-coupons:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('payments-coupons:modal-hide', (event) => {
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

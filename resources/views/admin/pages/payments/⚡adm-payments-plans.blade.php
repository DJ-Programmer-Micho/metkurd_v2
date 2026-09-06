<?php

use App\Support\Admin\ManagesPaymentPlansPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesPaymentPlansPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Payment Plans') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Service Plan Payments') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage App/API credits, concurrency, pricing, scopes, and activation state from one plan catalog.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <select class="form-select" wire:model.live="displayCurrencyCode" style="min-width: 180px;">
                        @foreach ($this->displayCurrencyOptions as $currencyCode => $currencyLabel)
                            <option value="{{ $currencyCode }}">{{ $currencyLabel }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreatePlanModal">{{ __('New Plan') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Plan Catalog') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['plans']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':active active, :paid paid.', ['active' => number_format($this->topStats['active_plans']), 'paid' => number_format($this->topStats['paid_plans'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Active Subscribers') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_subscribers']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Customers currently attached to a live service plan.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Plan Orders') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['orders']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':credits credits sold through subscriptions.', ['credits' => $this->formatCredits($this->topStats['credits'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Revenue') }}</p>
                    <h2 class="mb-1">{{ $this->formatCanonicalMoneyWithOptionalDisplay($this->topStats['revenue']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Lifetime paid revenue generated from service plan orders, stored canonically in IQD.') }}</p>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search plan name, code, or billing cycle...') }}">
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
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Type') }}</label>
                    <select class="form-select" wire:model.live="typeFilter">
                        <option value="all">{{ __('All plans') }}</option>
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="free">{{ __('Free') }}</option>
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Sort') }}</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'sort_order' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('sort_order')">
                            {{ __('Priority') }}
                            @if ($sortColumn === 'sort_order')
                                <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                            @endif
                        </button>
                        <button type="button" class="btn btn-sm {{ $sortColumn === 'active_subscribers' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('active_subscribers')">
                            {{ __('Subs') }}
                            @if ($sortColumn === 'active_subscribers')
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
                    <h5 class="card-title mb-1">{{ __('Plan Pricing Table') }}</h5>
                    <p class="text-muted mb-0">{{ __('Update credits, pricing, and activation without leaving the table view.') }}</p>
                </div>
                <div class="small text-muted">{{ __(':count plans matched the current filters.', ['count' => $this->plans->total()]) }}</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('App Credits') }}</th>
                            <th>{{ __('API Access') }}</th>
                            <th>{{ __('Runtime Limits') }}</th>
                            <th>{{ __('Pricing') }}</th>
                            <th>{{ __('Adoption') }}</th>
                            <th>{{ __('Revenue') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->plans as $plan)
                            <tr wire:key="payment-plan-{{ $plan->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $plan->name }}</span>
                                        <span class="text-muted small">{{ $plan->code }}</span>
                                        @php
                                            $billingCyclesLabel = collect($plan->billingIntervals())
                                                ->map(fn (string $cycle) => __(
                                                    match ($cycle) {
                                                        'yearly' => 'Yearly',
                                                        'lifetime' => 'Lifetime',
                                                        default => 'Monthly',
                                                    }
                                                ))
                                                ->join(', ');
                                        @endphp
                                        <span class="text-muted small">{{ __('Billing cycles: :value', ['value' => $billingCyclesLabel]) }}</span>
                                        <span class="text-muted small">{{ __('Payment mode: :value', ['value' => __($plan->checkoutPaymentMode()->label())]) }}</span>
                                        <span class="text-muted small">{{ __('Priority :value', ['value' => number_format((int) ($plan->sort_order ?? 0))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCredits((int) ($plan->app_monthly_credits_effective ?? $plan->app_monthly_credits ?? $plan->monthly_credits ?? 0)) }}</span>
                                        <span class="text-muted small">{{ __('App Monthly Credits') }}</span>
                                        <span class="text-muted small">{{ __('Legacy monthly credits stay synchronized.') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCredits((int) ($plan->api_monthly_credits_effective ?? $plan->api_monthly_credits ?? 0)) }}</span>
                                        <span class="text-muted small">{{ __('API Monthly Credits') }}</span>
                                        <span class="badge {{ (bool) ($plan->api_enabled ?? false) ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                            {{ (bool) ($plan->api_enabled ?? false) ? __('API Enabled') : __('API Disabled') }}
                                        </span>
                                        <span class="text-muted small">
                                            {{ collect((array) ($plan->api_allowed_tools ?? []))->take(3)->join(', ') ?: __('No API scopes') }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __('App Jobs: :value', ['value' => number_format((int) ($plan->concurrent_jobs_limit ?? 2))]) }}</span>
                                        <span class="text-muted small">{{ __('API RPM: :value', ['value' => number_format((int) ($plan->api_requests_per_minute ?? 0))]) }}</span>
                                        <span class="text-muted small">{{ __('API Jobs: :value', ['value' => number_format((int) ($plan->api_concurrent_jobs ?? 0))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        @if ($plan->is_free)
                                            <span class="fw-semibold text-success">{{ __('Free') }}</span>
                                            <span class="text-muted small">{{ __('No payment required') }}</span>
                                        @else
                                            <span class="fw-semibold">{{ __(':amount / month', ['amount' => $this->formatCanonicalPrimary($plan->price_iqd_monthly_effective)]) }}</span>
                                            <span class="text-muted small">{{ __(':amount / year', ['amount' => $this->formatCanonicalPrimary($plan->price_iqd_yearly_effective)]) }}</span>
                                            @if($this->formatOptionalDisplayMoney($plan->price_iqd_monthly_effective) || $this->formatOptionalDisplayMoney($plan->price_iqd_yearly_effective))
                                                <span class="text-muted small">
                                                    {{ __('~ :monthly / month | ~ :yearly / year', [
                                                        'monthly' => $this->formatOptionalDisplayMoney($plan->price_iqd_monthly_effective) ?? $this->formatCanonicalPrimary($plan->price_iqd_monthly_effective),
                                                        'yearly' => $this->formatOptionalDisplayMoney($plan->price_iqd_yearly_effective) ?? $this->formatCanonicalPrimary($plan->price_iqd_yearly_effective),
                                                    ]) }}
                                                </span>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __(':count active', ['count' => number_format((int) ($plan->active_subscribers ?? 0))]) }}</span>
                                        <span class="text-muted small">{{ __(':count paid orders', ['count' => number_format((int) ($plan->paid_orders ?? 0))]) }}</span>
                                        <span class="text-muted small">{{ $plan->last_order_at ? \Illuminate\Support\Carbon::parse($plan->last_order_at)->diffForHumans() : __('No paid orders yet') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCanonicalMoneyWithOptionalDisplay($plan->revenue) }}</span>
                                        <span class="text-muted small">{{ __(':credits credits sold', ['credits' => $this->formatCredits($plan->credits_sold)]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" {{ $plan->is_active ? 'checked' : '' }} wire:click="togglePlanStatus({{ $plan->id }})">
                                    </div>
                                    <span class="badge {{ $plan->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $plan->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditPlanModal({{ $plan->id }})">{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeletePlan({{ $plan->id }})">{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">{{ __('No service plans matched the current filters.') }}</td>
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
                        <h5 class="modal-title mb-1">{{ $editingPlanId ? __('Edit Service Plan') : __('Create Service Plan') }}</h5>
                        <p class="text-muted mb-0">{{ __('Adjust credits, concurrent job capacity, billing cycles, and plan pricing from one form.') }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetPlanForm"></button>
                </div>
                <form wire:submit="savePlan">
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Code') }}</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="{{ __('pro') }}">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Name') }}</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="{{ __('Pro') }}">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block mb-2">{{ __('Available Billing Cycles') }}</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="planBillingCycleMonthly" value="monthly" wire:model.defer="billingIntervals">
                                    <label class="form-check-label" for="planBillingCycleMonthly">{{ __('Monthly') }}</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="planBillingCycleYearly" value="yearly" wire:model.defer="billingIntervals">
                                    <label class="form-check-label" for="planBillingCycleYearly">{{ __('Yearly') }}</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="planBillingCycleLifetime" value="lifetime" wire:model.defer="billingIntervals">
                                    <label class="form-check-label" for="planBillingCycleLifetime">{{ __('Lifetime') }}</label>
                                </div>
                                <div class="form-text">{{ __('Select where this plan should appear on the customer pricing page.') }}</div>
                                @error('billingIntervals') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                @if($errors->has('billingIntervals.*'))
                                    <div class="text-danger small mt-1">{{ $errors->first('billingIntervals.*') }}</div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Payment Mode') }}</label>
                                <select class="form-select @error('paymentMode') is-invalid @enderror" wire:model.defer="paymentMode">
                                    <option value="one_time">{{ __('Manual Payment') }}</option>
                                    <option value="recurring">{{ __('Auto Renewal') }}</option>
                                </select>
                                <div class="form-text">{{ __('Manual Payment uses gateway checkout with no auto-renew.') }}</div>
                                @error('paymentMode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('App Monthly Credits') }}</label>
                                <input type="number" min="0" class="form-control @error('appMonthlyCredits') is-invalid @enderror" wire:model.defer="appMonthlyCredits">
                                <div class="form-text">{{ __('Keeps the legacy `monthly_credits` column synchronized for existing runtime paths.') }}</div>
                                @error('appMonthlyCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('API Monthly Credits') }}</label>
                                <input type="number" min="0" class="form-control @error('apiMonthlyCredits') is-invalid @enderror" wire:model.defer="apiMonthlyCredits">
                                @error('apiMonthlyCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('App Concurrent Jobs') }}</label>
                                <input type="number" min="1" class="form-control @error('concurrentJobsLimit') is-invalid @enderror" wire:model.defer="concurrentJobsLimit">
                                <div class="form-text">{{ __('Dashboard + `/api/mobile` concurrency limit.') }}</div>
                                @error('concurrentJobsLimit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="planApiEnabled" wire:model.defer="apiEnabled">
                                    <label class="form-check-label" for="planApiEnabled">{{ __('API Enabled') }}</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('API Requests / Minute') }}</label>
                                <input type="number" min="0" class="form-control @error('apiRequestsPerMinute') is-invalid @enderror" wire:model.defer="apiRequestsPerMinute">
                                @error('apiRequestsPerMinute') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('API Concurrent Jobs') }}</label>
                                <input type="number" min="0" class="form-control @error('apiConcurrentJobs') is-invalid @enderror" wire:model.defer="apiConcurrentJobs">
                                <div class="form-text">{{ __('Public `/api/v1` concurrency limit.') }}</div>
                                @error('apiConcurrentJobs') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('API Allowed Tools / Scopes') }}</label>
                                <textarea class="form-control font-monospace @error('apiAllowedToolsText') is-invalid @enderror" rows="4" wire:model.defer="apiAllowedToolsText" placeholder="tts:apollo-1-0v&#10;tts:apollo-1-5v&#10;translation:generate&#10;usage:read"></textarea>
                                <div class="form-text">{{ __('Enter one scope per line or use commas. Example: `tts:apollo-1-0v`, `tts:apollo-1-5v`, `translation:generate`, `usage:read`. Legacy scope aliases are accepted and saved as the new canonical scope names.') }}</div>
                                @error('apiAllowedToolsText') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Monthly Price (IQD)') }}</label>
                                <input type="number" min="0" step="250" class="form-control @error('priceIqdMonthly') is-invalid @enderror" wire:model.live.debounce.200ms="priceIqdMonthly">
                                @error('priceIqdMonthly') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Yearly Price (IQD)') }}</label>
                                <input type="number" min="0" step="250" class="form-control @error('priceIqdYearly') is-invalid @enderror" wire:model.live.debounce.200ms="priceIqdYearly">
                                @error('priceIqdYearly') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 bg-light-subtle h-100">
                                    <div class="fw-semibold mb-2">{{ __('Monthly preview from IQD base') }}</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($this->pricePreviewRows($priceIqdMonthly) as $preview)
                                            <span class="badge bg-body text-body border">
                                                {{ $preview['code'] }}: {{ $preview['formatted'] }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 bg-light-subtle h-100">
                                    <div class="fw-semibold mb-2">{{ __('Yearly preview from IQD base') }}</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($this->pricePreviewRows($priceIqdYearly) as $preview)
                                            <span class="badge bg-body text-body border">
                                                {{ $preview['code'] }}: {{ $preview['formatted'] }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="planIsFree" wire:model.defer="isFree">
                                    <label class="form-check-label" for="planIsFree">{{ __('Free plan') }}</label>
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-center">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="planIsActive" wire:model.defer="isActive">
                                    <label class="form-check-label" for="planIsActive">{{ __('Active') }}</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('UI Features JSON') }}</label>
                                <textarea class="form-control font-monospace @error('uiFeaturesJson') is-invalid @enderror" rows="5" wire:model.defer="uiFeaturesJson" placeholder='{{ __("{\"badge\":\"PRO\",\"highlight\":true}") }}'></textarea>
                                @error('uiFeaturesJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Meta JSON') }}</label>
                                <textarea class="form-control font-monospace @error('metaJson') is-invalid @enderror" rows="5" wire:model.defer="metaJson" placeholder='{{ __("{\"theme\":\"default\"}") }}'></textarea>
                                @error('metaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetPlanForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingPlanId ? __('Save Changes') : __('Create Plan') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentPlanDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Delete Plan') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deletePlanLabel }}</span>? {{ __('This only works for plans without subscriptions, pricing dependencies, or paid orders.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" wire:click="deletePlan">{{ __('Delete') }}</button>
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

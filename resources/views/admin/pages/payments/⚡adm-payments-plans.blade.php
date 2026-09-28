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

<x-slot:title>{{ __('admin_shell.plans') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid admin-service-workspace">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-capability-notice :capabilities="['admin.pricing']" />
    <x-admin-change-reason />
    <x-admin-v2-catalog />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('admin_shell.plans') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage App/API credits, concurrency, pricing, scopes, and activation state from one plan catalog.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <select class="form-select" wire:model.live="displayCurrencyCode" aria-label="{{ __('Currency') }}" style="min-width: 180px;">
                        @foreach ($this->displayCurrencyOptions as $currencyCode => $currencyLabel)
                            <option value="{{ $currencyCode }}">{{ $currencyLabel }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openCreatePlanModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('New Plan') }}</button>
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
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-plans-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search plan name, code, or billing cycle...') }}" id="admin-field-adm-payments-plans-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-plans-2">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-payments-plans-2">
                        <option value="all">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-plans-3">{{ __('Type') }}</label>
                    <select class="form-select" wire:model.live="typeFilter" id="admin-field-adm-payments-plans-3">
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
                            @php
                                $serviceSummary = app(\App\Support\Admin\AdminServiceWorkspace::class)->planSummary($plan);
                            @endphp
                            <tr wire:key="payment-plan-{{ $plan->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $plan->name }}</span>
                                        <details><summary>{{ __('admin_ux.configuration_reference') }}</summary><code dir="ltr">{{ $plan->code }}</code></details><x-admin-plan-service-summary :plan="$plan" :summary="$serviceSummary" />
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
                                        <span class="text-muted small">{{ __('admin_service.plan_defaults') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatCredits((int) ($plan->api_monthly_credits_effective ?? $plan->api_monthly_credits ?? 0)) }}</span>
                                        <span class="text-muted small">{{ __('API Monthly Credits') }}</span>
                                        <span class="badge {{ $serviceSummary['api']['api_enabled'] ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                            {{ $serviceSummary['api']['api_enabled'] ? __('API Enabled') : __('API Disabled') }}
                                        </span>
                                        <span class="text-muted small">
                                            <details><summary>{{ __('admin_ux.configuration_reference') }}</summary><bdi>{{ implode(', ', $serviceSummary['api']['allowed_tools']) ?: __('No API scopes') }}</bdi></details>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __('App Jobs: :value', ['value' => number_format(app(\App\Services\Plans\PlanConcurrencyService::class)->allowedConcurrentJobsForPlan($plan))]) }}</span>
                                        <span class="text-muted small">{{ __('API RPM: :value', ['value' => number_format((int) $serviceSummary['api']['requests_per_minute'])]) }}</span>
                                        <span class="text-muted small">{{ __('API Jobs: :value', ['value' => number_format((int) $serviceSummary['api']['concurrent_jobs'])]) }}</span>
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
                                        <input class="form-check-input" type="checkbox" role="switch" aria-label="{{ __('admin_shell.plan_activation', ['plan' => $plan->name]) }}" {{ $plan->is_active ? 'checked' : '' }} data-admin-method="togglePlanStatus" data-admin-args="{{ json_encode([$plan->id]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}" @disabled(! \App\Support\Admin\AdminUiAccess::can('admin.pricing'))>
                                    </div>
                                    <span class="badge {{ $plan->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $plan->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditPlanModal({{ $plan->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmDeletePlan({{ $plan->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Delete') }}</button>
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
                <form data-admin-method="savePlan" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>
                    @csrf
                    <div class="modal-body">
                        <x-admin-validation-summary />
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-4">{{ __('Code') }}</label>
                                <input type="text" class="form-control @error('code') is-invalid @enderror" wire:model.defer="code" placeholder="{{ __('pro') }}" data-admin-review id="admin-field-adm-payments-plans-4">
                                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-5">{{ __('Name') }}</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror" wire:model.defer="name" placeholder="{{ __('Pro') }}" data-admin-review id="admin-field-adm-payments-plans-5">
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
                                <label class="form-label" for="admin-field-adm-payments-plans-6">{{ __('Payment Mode') }}</label>
                                <select class="form-select @error('paymentMode') is-invalid @enderror" wire:model.defer="paymentMode" id="admin-field-adm-payments-plans-6" data-admin-review>
                                    <option value="one_time">{{ __('Manual Payment') }}</option>
                                    <option value="recurring">{{ __('Auto Renewal') }}</option>
                                </select>
                                <div class="form-text">{{ __('Manual Payment uses gateway checkout with no auto-renew.') }}</div>
                                @error('paymentMode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-7">{{ __('App Monthly Credits') }}</label>
                                <input type="number" min="0" class="form-control @error('appMonthlyCredits') is-invalid @enderror" wire:model.defer="appMonthlyCredits" id="admin-field-adm-payments-plans-7" data-admin-review>
                                <div class="form-text">{{ __('Keeps the legacy `monthly_credits` column synchronized for existing runtime paths.') }}</div>
                                @error('appMonthlyCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-8">{{ __('API Monthly Credits') }}</label>
                                <input type="number" min="0" class="form-control @error('apiMonthlyCredits') is-invalid @enderror" wire:model.defer="apiMonthlyCredits" id="admin-field-adm-payments-plans-8" data-admin-review>
                                @error('apiMonthlyCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-9">{{ __('App Concurrent Jobs') }}</label>
                                <input type="number" min="1" class="form-control @error('concurrentJobsLimit') is-invalid @enderror" wire:model.defer="concurrentJobsLimit" id="admin-field-adm-payments-plans-9" data-admin-review>
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
                                <label class="form-label" for="admin-field-adm-payments-plans-10">{{ __('API Requests / Minute') }}</label>
                                <input type="number" min="0" class="form-control @error('apiRequestsPerMinute') is-invalid @enderror" wire:model.defer="apiRequestsPerMinute" id="admin-field-adm-payments-plans-10" data-admin-review>
                                @error('apiRequestsPerMinute') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-11">{{ __('API Concurrent Jobs') }}</label>
                                <input type="number" min="0" class="form-control @error('apiConcurrentJobs') is-invalid @enderror" wire:model.defer="apiConcurrentJobs" id="admin-field-adm-payments-plans-11" data-admin-review>
                                <div class="form-text">{{ __('Public `/api/v1` concurrency limit.') }}</div>
                                @error('apiConcurrentJobs') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="admin-field-adm-payments-plans-12">{{ __('API Allowed Tools / Scopes') }}</label>
                                <textarea class="form-control font-monospace @error('apiAllowedToolsText') is-invalid @enderror" rows="4" wire:model.defer="apiAllowedToolsText" dir="ltr" placeholder="v2:*" id="admin-field-adm-payments-plans-12" data-admin-review></textarea>
                                <div class="form-text">{{ __('admin_p1.scope_help') }} <bdi dir="ltr">{{ implode(', ', app(\App\Services\CustomerApi\V2\ApiCatalog::class)->serviceScopes()) }}</bdi></div>
                                @error('apiAllowedToolsText') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if($editingPlanId)
                                    <button type="button" class="btn btn-outline-info mt-2" data-admin-method="saveApiScopes" data-admin-args="[]" data-admin-target="{{ $name }}" data-admin-impact="{{ __('admin_p1.scopes_impact') }}" @disabled(! \App\Support\Admin\AdminUiAccess::can('admin.pricing'))>{{ __('admin_p1.save_scopes') }}</button>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-13">{{ __('Monthly Price (IQD)') }}</label>
                                <input type="number" min="0" step="250" class="form-control @error('priceIqdMonthly') is-invalid @enderror" wire:model.live.debounce.200ms="priceIqdMonthly" id="admin-field-adm-payments-plans-13" data-admin-review>
                                @error('priceIqdMonthly') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-plans-14">{{ __('Yearly Price (IQD)') }}</label>
                                <input type="number" min="0" step="250" class="form-control @error('priceIqdYearly') is-invalid @enderror" wire:model.live.debounce.200ms="priceIqdYearly" id="admin-field-adm-payments-plans-14" data-admin-review>
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
                                <label class="form-label" for="admin-field-adm-payments-plans-15">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder" id="admin-field-adm-payments-plans-15" data-admin-review>
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
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea dir="ltr" class="form-control font-monospace @error('uiFeaturesJson') is-invalid @enderror" rows="5" wire:model.defer="uiFeaturesJson" placeholder='{{ __("{\"badge\":\"PRO\",\"highlight\":true}") }}'></textarea></details>
                                @error('uiFeaturesJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Meta JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea dir="ltr" class="form-control font-monospace @error('metaJson') is-invalid @enderror" rows="5" wire:model.defer="metaJson" placeholder='{{ __("{\"theme\":\"default\"}") }}'></textarea></details>
                                @error('metaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetPlanForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingPlanId ? __('Save Changes') : __('Create Plan') }}</button>
                    </div>
                </fieldset></form>
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
                        <x-admin-validation-summary />
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deletePlanLabel }}</span>? {{ __('This only works for plans without subscriptions, pricing dependencies, or paid orders.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-admin-target="{{ $deletePlanLabel }}" data-admin-method="deletePlan" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.delete_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>


</div>

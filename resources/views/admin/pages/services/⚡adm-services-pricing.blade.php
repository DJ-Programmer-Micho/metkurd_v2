<?php

use App\Support\Admin\ManagesServicePricingPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesServicePricingPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Services Pricing') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-capability-notice :capabilities="['admin.pricing']" />
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Service Pricing') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage grouped App, Mobile, and API metering rules while keeping legacy all-channel fallback safe at runtime.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.services.entitlements', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">{{ __('View Entitlements') }}</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openPricingRuleCreateModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('admin_ux.add_pricing') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Pricing Rules') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['rules']) }}</h2>
                    <p class="text-muted mb-0">{{ __('All configured pricing rows across global and plan-specific scopes.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Active Rules') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_rules']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Inactive rules stay preserved for audit and rollback.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Plan Overrides') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['plan_rules']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Rules with a service plan attached override global behavior.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Current Scope') }}</p>
                    <h2 class="mb-1">{{ number_format($this->groupedPricingRules->total()) }}</h2>
                    <p class="text-muted mb-0">{{ __('Grouped pricing configurations that match the active filters below.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <x-admin-v2-catalog />
    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search plan, action, metric, or rule type...') }}" id="admin-field-adm-services-pricing-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-2">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter" id="admin-field-adm-services-pricing-2">
                        <option value="all">{{ __('All Plans') }}</option>
                        @foreach ($this->planOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-3">{{ __('Tool Action') }}</label>
                    <select class="form-select" wire:model.live="actionFilter" id="admin-field-adm-services-pricing-3">
                        <option value="all">{{ __('All Actions') }}</option>
                        @foreach ($this->actionOptions as $action)
                            <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-4">{{ __('Rule Type') }}</label>
                    <select class="form-select" wire:model.live="ruleTypeFilter" id="admin-field-adm-services-pricing-4">
                        <option value="all">{{ __('All Types') }}</option>
                        <option value="free">{{ __('Free') }}</option>
                        <option value="fixed">{{ __('Fixed') }}</option>
                        <option value="unit">{{ __('Unit') }}</option>
                        <option value="matrix">{{ __('Matrix') }}</option>
                    </select>
                </div>
                <div class="col-xl-1 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-5">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-services-pricing-5">
                        <option value="all">{{ __('Any') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-xl-1 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-6">{{ __('Scope') }}</label>
                    <select class="form-select" wire:model.live="scopeFilter" id="admin-field-adm-services-pricing-6">
                        <option value="all">{{ __('Any') }}</option>
                        <option value="global">{{ __('Global') }}</option>
                        <option value="plan">{{ __('Plan') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-pricing-7">{{ __('Channel') }}</label>
                    <select class="form-select" wire:model.live="channelFilter" id="admin-field-adm-services-pricing-7">
                        <option value="all">{{ __('Any') }}</option>
                        <option value="app">{{ __('App') }}</option>
                        <option value="api">{{ __('API') }}</option>
                        <option value="mobile">{{ __('Mobile') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div>
                <h5 class="card-title mb-1">{{ __('Pricing Rules') }}</h5>
                <p class="text-muted mb-0">{{ __('Define shared metering settings and grouped App, Mobile, and API credit prices for each tool action.') }}</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Tool Action') }}</th>
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Rule Type') }}</th>
                            <th>{{ __('Metric') }}</th>
                            <th>{{ __('App Price') }}</th>
                            <th>{{ __('Mobile Price') }}</th>
                            <th>{{ __('API Price') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->groupedPricingRules as $group)
                            @php
                                $appPrice = $this->pricingGroupChannelValue($group, 'app');
                                $mobilePrice = $this->pricingGroupChannelValue($group, 'mobile');
                                $apiPrice = $this->pricingGroupChannelValue($group, 'api');
                                $legacyAllRule = $group['legacy_all_rule'];
                                $seedRule = $group['seed_rule'];
                            @endphp
                            <tr wire:key="pricing-group-{{ md5((string) $group['group_key']) }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $group['tool_action']?->name ?? __('Unknown Action') }}</span>
                                        <span class="text-muted small" dir="ltr">{{ $group['tool_action']?->full_code ?? __('n/a') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="badge bg-light text-body align-self-start">{{ $group['service_plan']?->name ?? __('Global Default') }}</span>
                                        <span class="text-muted small">{{ $group['service_plan'] ? __('Plan override') : __('Global default') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __(ucfirst($group['rule_type'])) }}</span>
                                        <span class="text-muted small">{{ $group['service_plan'] ? __('Plan override') : __('Global rule') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold" dir="ltr">{{ $group['metric_code'] }}</span>
                                        <span class="text-muted small">{{ __('Unit size: :value', ['value' => $this->formatDecimal($group['unit_size'], 4)]) }}</span>
                                        <span class="text-muted small">{{ __('Priority: :value', ['value' => number_format((int) $group['priority'])]) }}</span>
                                        <span class="text-muted small">{{ __(':mode / step :step', ['mode' => __($group['rounding_mode']), 'step' => $this->formatDecimal($group['rounding_step'], 4)]) }}</span>
                                        <span class="text-muted small">{{ __('Minimum :value', ['value' => number_format((int) $group['minimum_credits'])]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $appPrice['label'] }}</span>
                                        @if ($legacyAllRule && ! $group['app_rule'])
                                            <span class="text-muted small">{{ __('Legacy All: :value credits', ['value' => $this->formatDecimal($legacyAllRule->credits_per_unit, 4)]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $mobilePrice['label'] }}</span>
                                        @if ($legacyAllRule && ! $group['mobile_rule'])
                                            <span class="text-muted small">{{ __('Legacy All: :value credits', ['value' => $this->formatDecimal($legacyAllRule->credits_per_unit, 4)]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $apiPrice['label'] }}</span>
                                        @if ($legacyAllRule && ! $group['api_rule'])
                                            <span class="text-muted small">{{ __('Legacy All: :value credits', ['value' => $this->formatDecimal($legacyAllRule->credits_per_unit, 4)]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge {{ $this->groupedStatusBadgeClasses($group['status_variant']) }} align-self-start">{{ $group['status_label'] }}</span>
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach ($this->groupedStatusChannelBadges($group) as $badge)
                                                <span class="badge {{ $badge['classes'] }}">{{ $badge['label'] }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" data-admin-method="togglePricingRuleStatus" data-admin-args="{{ json_encode([$group['seed_rule_id']]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ $group['has_active_primary'] ? __('Disable') : __('Enable') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openPricingRuleEditModal({{ $group['seed_rule_id'] }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmPricingRuleDelete({{ $group['seed_rule_id'] }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">{{ __('No pricing rules matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->groupedPricingRules->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicePricingRuleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form data-admin-method="savePricingRule" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingRuleId ? __('Edit Pricing Group') : __('Create Pricing Group') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetRuleForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-services-pricing-8">{{ __('Tool Action') }}</label>
                                <select class="form-select @error('ruleToolActionId') is-invalid @enderror" wire:model.defer="ruleToolActionId" id="admin-field-adm-services-pricing-8">
                                    <option value="">{{ __('Choose action...') }}</option>
                                    @foreach ($this->actionOptions as $action)
                                        <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                                    @endforeach
                                </select>
                                @error('ruleToolActionId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-services-pricing-9">{{ __('Service Plan') }}</label>
                                <select class="form-select @error('ruleServicePlanId') is-invalid @enderror" wire:model.defer="ruleServicePlanId" id="admin-field-adm-services-pricing-9">
                                    <option value="">{{ __('Global default') }}</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('ruleServicePlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-pricing-10">{{ __('Rule Type') }}</label>
                                <select class="form-select" wire:model.defer="ruleType" id="admin-field-adm-services-pricing-10">
                                    <option value="free">{{ __('Free') }}</option>
                                    <option value="fixed">{{ __('Fixed') }}</option>
                                    <option value="unit">{{ __('Unit') }}</option>
                                    <option value="matrix">{{ __('Matrix') }}</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-pricing-11">{{ __('Priority') }}</label>
                                <input type="number" min="0" class="form-control @error('rulePriority') is-invalid @enderror" wire:model.defer="rulePriority" id="admin-field-adm-services-pricing-11">
                                @error('rulePriority') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-pricing-12">{{ __('Metric Code') }}</label>
                                <input dir="ltr" type="text" class="form-control @error('ruleMetricCode') is-invalid @enderror" wire:model.defer="ruleMetricCode" placeholder="character" id="admin-field-adm-services-pricing-12">
                                @error('ruleMetricCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-13">{{ __('Status') }}</label>
                                <select class="form-select" wire:model.defer="ruleStatus" id="admin-field-adm-services-pricing-13">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-14">{{ __('Unit Size') }}</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control @error('ruleUnitSize') is-invalid @enderror" wire:model.defer="ruleUnitSize" id="admin-field-adm-services-pricing-14">
                                @error('ruleUnitSize') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-pricing-15">{{ __('App Dashboard Credits / Unit') }}</label>
                                <input type="number" step="0.0001" min="0" class="form-control @error('ruleAppCreditsPerUnit') is-invalid @enderror" wire:model.defer="ruleAppCreditsPerUnit" id="admin-field-adm-services-pricing-15">
                                <div class="form-text">{{ __('Dashboard and normal web app usage.') }}</div>
                                @error('ruleAppCreditsPerUnit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-pricing-16">{{ __('Mobile API Credits / Unit') }}</label>
                                <input type="number" step="0.0001" min="0" class="form-control @error('ruleMobileCreditsPerUnit') is-invalid @enderror" wire:model.defer="ruleMobileCreditsPerUnit" id="admin-field-adm-services-pricing-16">
                                <div class="form-text">{{ __('Mobile client API pricing.') }}</div>
                                @error('ruleMobileCreditsPerUnit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-pricing-17">{{ __('Public API Credits / Unit') }}</label>
                                <input type="number" step="0.0001" min="0" class="form-control @error('ruleApiCreditsPerUnit') is-invalid @enderror" wire:model.defer="ruleApiCreditsPerUnit" id="admin-field-adm-services-pricing-17">
                                <div class="form-text">{{ __('Public Customer API pricing.') }}</div>
                                @error('ruleApiCreditsPerUnit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-18">{{ __('Rounding Mode') }}</label>
                                <select class="form-select" wire:model.defer="ruleRoundingMode" id="admin-field-adm-services-pricing-18">
                                    <option value="none">{{ __('none') }}</option>
                                    <option value="ceil">{{ __('ceil') }}</option>
                                    <option value="floor">{{ __('floor') }}</option>
                                    <option value="nearest">{{ __('nearest') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-19">{{ __('Rounding Step') }}</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control @error('ruleRoundingStep') is-invalid @enderror" wire:model.defer="ruleRoundingStep" id="admin-field-adm-services-pricing-19">
                                @error('ruleRoundingStep') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-20">{{ __('Minimum Credits') }}</label>
                                <input type="number" min="0" class="form-control @error('ruleMinimumCredits') is-invalid @enderror" wire:model.defer="ruleMinimumCredits" id="admin-field-adm-services-pricing-20">
                                @error('ruleMinimumCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-21">{{ __('Starts At') }}</label>
                                <input type="datetime-local" class="form-control" wire:model.defer="ruleStartsAt" id="admin-field-adm-services-pricing-21">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-pricing-22">{{ __('Ends At') }}</label>
                                <input type="datetime-local" class="form-control" wire:model.defer="ruleEndsAt" id="admin-field-adm-services-pricing-22">
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Conditions JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea dir="ltr" class="form-control font-monospace @error('ruleConditionsJson') is-invalid @enderror" rows="5" wire:model.defer="ruleConditionsJson"></textarea></details>
                                @error('ruleConditionsJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Config JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea dir="ltr" class="form-control font-monospace @error('ruleConfigJson') is-invalid @enderror" rows="5" wire:model.defer="ruleConfigJson"></textarea></details>
                                @error('ruleConfigJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetRuleForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingRuleId ? __('Save Changes') : __('Create Rule') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicePricingDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Confirm Delete') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">{{ __('Delete this pricing group?') }}</p>
                    <p class="mb-2"><span class="fw-semibold">{{ $deleteLabel }}</span></p>
                    <p class="mb-0 text-muted">{{ __('This will remove App, Mobile, and Public API pricing rows for this tool/action configuration. Legacy All Channel fallback rows will be preserved unless explicitly handled.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-admin-target="{{ $deleteLabel }}" data-admin-method="performDelete" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.delete_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__SERVICES_PRICING_MODAL_EVENTS__) {
                        return;
                    }

                    window.__SERVICES_PRICING_MODAL_EVENTS__ = true;

                    const modalIds = [
                        'servicePricingRuleModal',
                        'servicePricingDeleteModal',
                    ];

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

                    window.addEventListener('services-pricing:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('services-pricing:modal-hide', (event) => {
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

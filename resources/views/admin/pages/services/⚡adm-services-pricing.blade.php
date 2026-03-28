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
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Service Pricing') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage metering rules, plan overrides, and credit charging logic for every tool action.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.services.entitlements', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">{{ __('View Entitlements') }}</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openPricingRuleCreateModal">{{ __('New Pricing Rule') }}</button>
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
                    <h2 class="mb-1">{{ number_format($this->pricingRules->total()) }}</h2>
                    <p class="text-muted mb-0">{{ __('Pricing rows that match the active filters below.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search plan, action, metric, or rule type...') }}">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">{{ __('All Plans') }}</option>
                        @foreach ($this->planOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Tool Action') }}</label>
                    <select class="form-select" wire:model.live="actionFilter">
                        <option value="all">{{ __('All Actions') }}</option>
                        @foreach ($this->actionOptions as $action)
                            <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Rule Type') }}</label>
                    <select class="form-select" wire:model.live="ruleTypeFilter">
                        <option value="all">{{ __('All Types') }}</option>
                        <option value="free">{{ __('Free') }}</option>
                        <option value="fixed">{{ __('Fixed') }}</option>
                        <option value="unit">{{ __('Unit') }}</option>
                        <option value="matrix">{{ __('Matrix') }}</option>
                    </select>
                </div>
                <div class="col-xl-1 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">{{ __('Any') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                <div class="col-xl-1 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Scope') }}</label>
                    <select class="form-select" wire:model.live="scopeFilter">
                        <option value="all">{{ __('Any') }}</option>
                        <option value="global">{{ __('Global') }}</option>
                        <option value="plan">{{ __('Plan') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div>
                <h5 class="card-title mb-1">{{ __('Pricing Rules') }}</h5>
                <p class="text-muted mb-0">{{ __('Define the metering logic and credit charge for each tool action.') }}</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Tool Action') }}</th>
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Type / Scope') }}</th>
                            <th>{{ __('Metering') }}</th>
                            <th>{{ __('Credits') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pricingRules as $rule)
                            <tr wire:key="pricing-rule-{{ $rule->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $rule->toolAction?->name ?? __('Unknown Action') }}</span>
                                        <span class="text-muted small">{{ $rule->toolAction?->full_code ?? __('n/a') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-body">{{ $rule->servicePlan?->name ?? __('Global Default') }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold text-capitalize">{{ $rule->rule_type }}</span>
                                        <span class="text-muted small">{{ $rule->service_plan_id ? __('Plan override') : __('Global rule') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $rule->metric_code }}</span>
                                        <span class="text-muted small">{{ __('Unit size: :value', ['value' => $this->formatDecimal($rule->unit_size, 4)]) }}</span>
                                        <span class="text-muted small">{{ __('Priority: :value', ['value' => number_format((int) $rule->priority)]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __(':value credits', ['value' => $this->formatDecimal($rule->credits_per_unit, 4)]) }}</span>
                                        <span class="text-muted small">{{ __(':mode / step :step', ['mode' => $rule->rounding_mode, 'step' => $this->formatDecimal($rule->rounding_step, 4)]) }}</span>
                                        <span class="text-muted small">{{ __('Minimum :value', ['value' => number_format((int) $rule->minimum_credits)]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge {{ $this->statusBadgeClasses((bool) $rule->is_active) }}">{{ $rule->is_active ? __('Active') : __('Inactive') }}</span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="togglePricingRuleStatus({{ $rule->id }})">{{ $rule->is_active ? __('Disable') : __('Enable') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openPricingRuleEditModal({{ $rule->id }})">{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmPricingRuleDelete({{ $rule->id }})">{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">{{ __('No pricing rules matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->pricingRules->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicePricingRuleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="savePricingRule">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingRuleId ? __('Edit Pricing Rule') : __('Create Pricing Rule') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetRuleForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Tool Action') }}</label>
                                <select class="form-select @error('ruleToolActionId') is-invalid @enderror" wire:model.defer="ruleToolActionId">
                                    <option value="">{{ __('Choose action...') }}</option>
                                    @foreach ($this->actionOptions as $action)
                                        <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                                    @endforeach
                                </select>
                                @error('ruleToolActionId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Service Plan') }}</label>
                                <select class="form-select @error('ruleServicePlanId') is-invalid @enderror" wire:model.defer="ruleServicePlanId">
                                    <option value="">{{ __('Global default') }}</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('ruleServicePlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Rule Type') }}</label>
                                <select class="form-select" wire:model.defer="ruleType">
                                    <option value="free">{{ __('Free') }}</option>
                                    <option value="fixed">{{ __('Fixed') }}</option>
                                    <option value="unit">{{ __('Unit') }}</option>
                                    <option value="matrix">{{ __('Matrix') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Priority') }}</label>
                                <input type="number" min="0" class="form-control @error('rulePriority') is-invalid @enderror" wire:model.defer="rulePriority">
                                @error('rulePriority') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Metric Code') }}</label>
                                <input type="text" class="form-control @error('ruleMetricCode') is-invalid @enderror" wire:model.defer="ruleMetricCode" placeholder="character">
                                @error('ruleMetricCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Status') }}</label>
                                <select class="form-select" wire:model.defer="ruleStatus">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Unit Size') }}</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control @error('ruleUnitSize') is-invalid @enderror" wire:model.defer="ruleUnitSize">
                                @error('ruleUnitSize') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Credits / Unit') }}</label>
                                <input type="number" step="0.0001" min="0" class="form-control @error('ruleCreditsPerUnit') is-invalid @enderror" wire:model.defer="ruleCreditsPerUnit">
                                @error('ruleCreditsPerUnit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Rounding Mode') }}</label>
                                <select class="form-select" wire:model.defer="ruleRoundingMode">
                                    <option value="none">{{ __('none') }}</option>
                                    <option value="ceil">{{ __('ceil') }}</option>
                                    <option value="floor">{{ __('floor') }}</option>
                                    <option value="nearest">{{ __('nearest') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Rounding Step') }}</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control @error('ruleRoundingStep') is-invalid @enderror" wire:model.defer="ruleRoundingStep">
                                @error('ruleRoundingStep') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Minimum Credits') }}</label>
                                <input type="number" min="0" class="form-control @error('ruleMinimumCredits') is-invalid @enderror" wire:model.defer="ruleMinimumCredits">
                                @error('ruleMinimumCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Starts At') }}</label>
                                <input type="datetime-local" class="form-control" wire:model.defer="ruleStartsAt">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('Ends At') }}</label>
                                <input type="datetime-local" class="form-control" wire:model.defer="ruleEndsAt">
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Conditions JSON') }}</label>
                                <textarea class="form-control font-monospace @error('ruleConditionsJson') is-invalid @enderror" rows="5" wire:model.defer="ruleConditionsJson"></textarea>
                                @error('ruleConditionsJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Config JSON') }}</label>
                                <textarea class="form-control font-monospace @error('ruleConfigJson') is-invalid @enderror" rows="5" wire:model.defer="ruleConfigJson"></textarea>
                                @error('ruleConfigJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetRuleForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingRuleId ? __('Save Changes') : __('Create Rule') }}</button>
                    </div>
                </form>
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
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deleteLabel }}</span>? {{ __('Historical usage rows will keep their recorded values even if the admin rule is removed later.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" wire:click="performDelete">{{ __('Delete') }}</button>
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

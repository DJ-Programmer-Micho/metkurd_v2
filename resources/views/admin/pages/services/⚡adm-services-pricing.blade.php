<?php

use App\Support\Admin\ManagesServicePricingPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Services Pricing | METKURD')]
class extends Component
{
    use ManagesServicePricingPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Service Pricing</h4>
                    <p class="text-muted mb-0">Manage metering rules, plan overrides, and credit charging logic for every tool action.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.services.entitlements', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">View Entitlements</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                    <button type="button" class="btn btn-primary" wire:click="openPricingRuleCreateModal">New Pricing Rule</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Pricing Rules</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['rules']) }}</h2>
                    <p class="text-muted mb-0">All configured pricing rows across global and plan-specific scopes.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Active Rules</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_rules']) }}</h2>
                    <p class="text-muted mb-0">Inactive rules stay preserved for audit and rollback.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Plan Overrides</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['plan_rules']) }}</h2>
                    <p class="text-muted mb-0">Rules with a service plan attached override global behavior.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Current Scope</p>
                    <h2 class="mb-1">{{ number_format($this->pricingRules->total()) }}</h2>
                    <p class="text-muted mb-0">Pricing rows that match the active filters below.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12">Search</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search plan, action, metric, or rule type...">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Plan</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">All Plans</option>
                        @foreach ($this->planOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Tool Action</label>
                    <select class="form-select" wire:model.live="actionFilter">
                        <option value="all">All Actions</option>
                        @foreach ($this->actionOptions as $action)
                            <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Rule Type</label>
                    <select class="form-select" wire:model.live="ruleTypeFilter">
                        <option value="all">All Types</option>
                        <option value="free">Free</option>
                        <option value="fixed">Fixed</option>
                        <option value="unit">Unit</option>
                        <option value="matrix">Matrix</option>
                    </select>
                </div>
                <div class="col-xl-1 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Status</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">Any</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-xl-1 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Scope</label>
                    <select class="form-select" wire:model.live="scopeFilter">
                        <option value="all">Any</option>
                        <option value="global">Global</option>
                        <option value="plan">Plan</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div>
                <h5 class="card-title mb-1">Pricing Rules</h5>
                <p class="text-muted mb-0">Define the metering logic and credit charge for each tool action.</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Tool Action</th>
                            <th>Plan</th>
                            <th>Type / Scope</th>
                            <th>Metering</th>
                            <th>Credits</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pricingRules as $rule)
                            <tr wire:key="pricing-rule-{{ $rule->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $rule->toolAction?->name ?? 'Unknown Action' }}</span>
                                        <span class="text-muted small">{{ $rule->toolAction?->full_code ?? 'n/a' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-body">{{ $rule->servicePlan?->name ?? 'Global Default' }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold text-capitalize">{{ $rule->rule_type }}</span>
                                        <span class="text-muted small">{{ $rule->service_plan_id ? 'Plan override' : 'Global rule' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $rule->metric_code }}</span>
                                        <span class="text-muted small">Unit size: {{ $this->formatDecimal($rule->unit_size, 4) }}</span>
                                        <span class="text-muted small">Priority: {{ number_format((int) $rule->priority) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatDecimal($rule->credits_per_unit, 4) }} credits</span>
                                        <span class="text-muted small">{{ $rule->rounding_mode }} / step {{ $this->formatDecimal($rule->rounding_step, 4) }}</span>
                                        <span class="text-muted small">Minimum {{ number_format((int) $rule->minimum_credits) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge {{ $this->statusBadgeClasses((bool) $rule->is_active) }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="togglePricingRuleStatus({{ $rule->id }})">{{ $rule->is_active ? 'Disable' : 'Enable' }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openPricingRuleEditModal({{ $rule->id }})">Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmPricingRuleDelete({{ $rule->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No pricing rules matched the current filters.</td>
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
                        <h5 class="modal-title">{{ $editingRuleId ? 'Edit Pricing Rule' : 'Create Pricing Rule' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetRuleForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Tool Action</label>
                                <select class="form-select @error('ruleToolActionId') is-invalid @enderror" wire:model.defer="ruleToolActionId">
                                    <option value="">Choose action...</option>
                                    @foreach ($this->actionOptions as $action)
                                        <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                                    @endforeach
                                </select>
                                @error('ruleToolActionId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Service Plan</label>
                                <select class="form-select @error('ruleServicePlanId') is-invalid @enderror" wire:model.defer="ruleServicePlanId">
                                    <option value="">Global default</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('ruleServicePlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Rule Type</label>
                                <select class="form-select" wire:model.defer="ruleType">
                                    <option value="free">Free</option>
                                    <option value="fixed">Fixed</option>
                                    <option value="unit">Unit</option>
                                    <option value="matrix">Matrix</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Priority</label>
                                <input type="number" min="0" class="form-control @error('rulePriority') is-invalid @enderror" wire:model.defer="rulePriority">
                                @error('rulePriority') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Metric Code</label>
                                <input type="text" class="form-control @error('ruleMetricCode') is-invalid @enderror" wire:model.defer="ruleMetricCode" placeholder="character">
                                @error('ruleMetricCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" wire:model.defer="ruleStatus">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Unit Size</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control @error('ruleUnitSize') is-invalid @enderror" wire:model.defer="ruleUnitSize">
                                @error('ruleUnitSize') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Credits / Unit</label>
                                <input type="number" step="0.0001" min="0" class="form-control @error('ruleCreditsPerUnit') is-invalid @enderror" wire:model.defer="ruleCreditsPerUnit">
                                @error('ruleCreditsPerUnit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Rounding Mode</label>
                                <select class="form-select" wire:model.defer="ruleRoundingMode">
                                    <option value="none">none</option>
                                    <option value="ceil">ceil</option>
                                    <option value="floor">floor</option>
                                    <option value="nearest">nearest</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Rounding Step</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control @error('ruleRoundingStep') is-invalid @enderror" wire:model.defer="ruleRoundingStep">
                                @error('ruleRoundingStep') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Minimum Credits</label>
                                <input type="number" min="0" class="form-control @error('ruleMinimumCredits') is-invalid @enderror" wire:model.defer="ruleMinimumCredits">
                                @error('ruleMinimumCredits') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Starts At</label>
                                <input type="datetime-local" class="form-control" wire:model.defer="ruleStartsAt">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ends At</label>
                                <input type="datetime-local" class="form-control" wire:model.defer="ruleEndsAt">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Conditions JSON</label>
                                <textarea class="form-control font-monospace @error('ruleConditionsJson') is-invalid @enderror" rows="5" wire:model.defer="ruleConditionsJson"></textarea>
                                @error('ruleConditionsJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Config JSON</label>
                                <textarea class="form-control font-monospace @error('ruleConfigJson') is-invalid @enderror" rows="5" wire:model.defer="ruleConfigJson"></textarea>
                                @error('ruleConfigJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetRuleForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingRuleId ? 'Save Changes' : 'Create Rule' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicePricingDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Delete <span class="fw-semibold">{{ $deleteLabel }}</span>? Historical usage rows will keep their recorded values even if the admin rule is removed later.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">Cancel</button>
                    <button type="button" class="btn btn-danger" wire:click="performDelete">Delete</button>
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

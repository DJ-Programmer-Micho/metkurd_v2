<?php

use App\Support\Admin\ManagesServiceToolsPage;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Services Tools | METKURD')]
class extends Component
{
    use ManagesServiceToolsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Services Tools</h4>
                    <p class="text-muted mb-0">Admin CRUD for tools, actions, operational status, and usage-aware filtering.</p>
                </div>

                <div class="page-title-right d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">
                        <i class="ri-filter-off-line align-bottom me-1"></i>
                        Clear Filters
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="openToolCreateModal">
                        <i class="ri-add-line align-bottom me-1"></i>
                        New Tool
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Registered Tools</p>
                    <div class="d-flex align-items-center justify-content-between">
                        <h2 class="mb-0">{{ number_format($this->topStats['tools']) }}</h2>
                        <span class="badge bg-primary-subtle text-primary">Live catalog</span>
                    </div>
                    <p class="text-muted mb-0 mt-2">{{ number_format($this->topStats['actions']) }} total tool actions configured.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Maintenance Queue</p>
                    <div class="d-flex align-items-center justify-content-between">
                        <h2 class="mb-0">{{ number_format($this->topStats['maintenance']) }}</h2>
                        <span class="badge bg-warning-subtle text-warning">Needs review</span>
                    </div>
                    <p class="text-muted mb-0 mt-2">Maintenance mode stays visually distinct across the table and quick actions.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">30 Day Usage</p>
                    <div class="d-flex align-items-center justify-content-between">
                        <h2 class="mb-0">{{ number_format($this->topStats['runs_30d']) }}</h2>
                        <span class="badge bg-success-subtle text-success">Recent runs</span>
                    </div>
                    <p class="text-muted mb-0 mt-2">{{ number_format($this->topStats['credits_30d']) }} credits consumed in the last 30 days.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Current View</p>
                    <div class="d-flex align-items-center justify-content-between">
                        <h2 class="mb-0">{{ number_format($this->tools->total()) }}</h2>
                        <span class="badge bg-info-subtle text-info">Filtered</span>
                    </div>
                    <p class="text-muted mb-0 mt-2">Server-side filters are applied before pagination for reliable admin reporting.</p>
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
                        <input
                            type="text"
                            class="form-control"
                            wire:model.live.debounce.350ms="search"
                            placeholder="Search tool name, code, category, or action..."
                        >
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Status</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Category</label>
                    <select class="form-select" wire:model.live="categoryFilter">
                        <option value="all">All Categories</option>
                        @foreach ($this->categoryOptions as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Metric</label>
                    <select class="form-select" wire:model.live="metricFilter">
                        <option value="all">All Metrics</option>
                        @foreach ($metricOptions as $metricCode => $metricLabel)
                            <option value="{{ $metricCode }}">{{ $metricLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Usage Band</label>
                    <select class="form-select" wire:model.live="usageFilter">
                        <option value="all">Any Volume</option>
                        <option value="idle">No Usage Yet</option>
                        <option value="used">Used At Least Once</option>
                        <option value="busy">25+ Jobs</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">Tool Registry</h5>
                    <p class="text-muted mb-0">Each tool row includes pricing hints, usage rollups, and inline action management.</p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'sort_order' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('sort_order')">
                        Sort: Order
                        @if ($sortColumn === 'sort_order')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'name' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('name')">
                        Sort: Name
                        @if ($sortColumn === 'name')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'usage' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('usage')">
                        Sort: Usage
                        @if ($sortColumn === 'usage')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                    <button type="button" class="btn btn-sm {{ $sortColumn === 'credits' ? 'btn-secondary' : 'btn-soft-secondary' }}" wire:click="sortByColumn('credits')">
                        Sort: Credits
                        @if ($sortColumn === 'credits')
                            <i class="ri-arrow-{{ $sortDirection === 'asc' ? 'up' : 'down' }}-line align-bottom ms-1"></i>
                        @endif
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th style="width: 56px;"></th>
                            <th>Tool</th>
                            <th>Category</th>
                            <th>Actions</th>
                            <th>Credit Cost / Use</th>
                            <th>Usage Metrics</th>
                            <th>Status</th>
                            <th class="text-end" style="width: 240px;">Admin Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->tools as $tool)
                            @php
                                $isExpanded = in_array($tool->id, $expandedTools, true);
                                $category = data_get($tool->meta, 'category', 'Uncategorized');
                                $notes = data_get($tool->meta, 'notes');
                                $usageCount = (int) ($tool->usage_events_count ?? 0);
                                $creditsSpent = (int) ($tool->total_credits_spent ?? 0);
                                $isMaintenance = !$tool->is_active;
                            @endphp
                            <tr class="{{ $isMaintenance ? 'table-danger' : '' }}" wire:key="tool-row-{{ $tool->id }}">
                                <td>
                                    <button type="button" class="btn btn-sm btn-icon btn-soft-secondary" wire:click="toggleExpandedTool({{ $tool->id }})">
                                        <i class="ri-arrow-{{ $isExpanded ? 'down' : 'right' }}-s-line"></i>
                                    </button>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <div class="d-flex align-items-center gap-2">
                                            <h6 class="{{ $isMaintenance ? 'text-dark' : '' }} mb-0">{{ $tool->name }}</h6>
                                            <span class="badge {{ $this->statusBadgeClasses((bool) $tool->is_active) }}">{{ $this->statusLabel((bool) $tool->is_active) }}</span>
                                        </div>
                                        <span class="text-muted small">{{ $tool->code }}</span>
                                        @if ($notes)
                                            <span class="text-muted small mt-1">{{ $notes }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-body">{{ $category }}</span></td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <span class="fw-semibold">{{ number_format((int) $tool->actions_count) }} total</span>
                                        <span class="text-muted small">{{ number_format((int) $tool->active_actions_count) }} active actions</span>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            @foreach ($tool->actions->take(3) as $actionChip)
                                                <span class="badge bg-info-subtle text-info">{{ $actionChip->action_code }}</span>
                                            @endforeach
                                            @if ($tool->actions->count() > 3)
                                                <span class="badge bg-light text-body">+{{ $tool->actions->count() - 3 }} more</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->formatPricingRange($tool->min_credit_cost, $tool->max_credit_cost, (int) $tool->active_pricing_rules_count) }}</span>
                                        <span class="text-muted small">{{ number_format((int) $tool->active_pricing_rules_count) }} active pricing rules</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format($usageCount) }} runs</span>
                                        <span class="text-muted small">{{ number_format($creditsSpent) }} credits charged</span>
                                        <span class="text-muted small">
                                            {{ $tool->last_used_at ? \Illuminate\Support\Carbon::parse($tool->last_used_at)->diffForHumans() : 'Never used' }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm {{ $tool->is_active ? 'btn-success' : 'btn-danger' }}" wire:click="toggleToolStatus({{ $tool->id }})">
                                        {{ $tool->is_active ? 'Set Maintenance' : 'Restore Active' }}
                                    </button>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openActionCreateModal({{ $tool->id }})">Add Action</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openToolEditModal({{ $tool->id }})">Quick Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmToolDelete({{ $tool->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            @if ($isExpanded)
                                <tr class="bg-light-subtle" wire:key="tool-action-panel-{{ $tool->id }}">
                                    <td colspan="8" class="p-0">
                                        <div class="p-3 border-top">
                                            <div class="d-flex align-items-center justify-content-between mb-3">
                                                <div>
                                                    <h6 class="mb-1">Actions for {{ $tool->name }}</h6>
                                                    <p class="text-muted mb-0">These actions inherit the tool code and define the billable metrics used by pricing rules.</p>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-primary" wire:click="openActionCreateModal({{ $tool->id }})">New Action</button>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr class="text-uppercase text-muted">
                                                            <th>Action</th>
                                                            <th>Metric</th>
                                                            <th>Pricing</th>
                                                            <th>Usage</th>
                                                            <th>Status</th>
                                                            <th class="text-end">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse ($tool->actions as $action)
                                                            <tr wire:key="tool-action-{{ $action->id }}">
                                                                <td>
                                                                    <div class="d-flex flex-column">
                                                                        <span class="fw-semibold">{{ $action->name }}</span>
                                                                        <span class="text-muted small">{{ $action->full_code }}</span>
                                                                    </div>
                                                                </td>
                                                                <td><span class="badge bg-light text-body">{{ $this->metricLabel($action->default_metric_code) }}</span></td>
                                                                <td>
                                                                    <div class="d-flex flex-column">
                                                                        <span class="fw-semibold">{{ $this->formatPricingRange($action->min_credit_cost, $action->max_credit_cost, (int) $action->active_pricing_rules_count) }}</span>
                                                                        <span class="text-muted small">{{ number_format((int) $action->active_pricing_rules_count) }} rule(s)</span>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <div class="d-flex flex-column">
                                                                        <span class="fw-semibold">{{ number_format((int) $action->usage_events_count) }} runs</span>
                                                                        <span class="text-muted small">{{ number_format((int) ($action->usage_events_total_credits ?? 0)) }} credits charged</span>
                                                                        <span class="text-muted small">
                                                                            {{ $action->last_used_at ? \Illuminate\Support\Carbon::parse($action->last_used_at)->diffForHumans() : 'Never used' }}
                                                                        </span>
                                                                    </div>
                                                                </td>
                                                                <td><span class="badge {{ $this->statusBadgeClasses((bool) $action->is_active) }}">{{ $this->statusLabel((bool) $action->is_active) }}</span></td>
                                                                <td class="text-end">
                                                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="toggleActionStatus({{ $action->id }})">{{ $action->is_active ? 'Maintenance' : 'Activate' }}</button>
                                                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openActionEditModal({{ $action->id }})">Edit</button>
                                                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmActionDelete({{ $action->id }})">Delete</button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        @empty
                                                            <tr>
                                                                <td colspan="6" class="text-center py-4 text-muted">No actions configured for this tool yet.</td>
                                                            </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="ri-search-eye-line d-block fs-1 mb-2"></i>
                                        No tools matched the current filters.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->tools->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicesToolModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveTool">
                    @csrf
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1">{{ $editingToolId ? 'Quick Edit Tool' : 'Create Tool' }}</h5>
                            <p class="text-muted mb-0">Operational fields stay editable without changing the integration code after creation.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetToolForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Tool Code</label>
                                <input type="text" class="form-control @error('toolCode') is-invalid @enderror" wire:model.defer="toolCode" placeholder="tts" {{ $editingToolId ? 'disabled' : '' }}>
                                @error('toolCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if ($editingToolId)
                                    <div class="form-text">Codes are locked after creation to protect tool/action references.</div>
                                @endif
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Tool Name</label>
                                <input type="text" class="form-control @error('toolName') is-invalid @enderror" wire:model.defer="toolName" placeholder="Text To Speech">
                                @error('toolName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Sort Order</label>
                                <input type="number" min="0" class="form-control @error('toolSortOrder') is-invalid @enderror" wire:model.defer="toolSortOrder">
                                @error('toolSortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category</label>
                                <input type="text" class="form-control @error('toolCategory') is-invalid @enderror" wire:model.defer="toolCategory" placeholder="Speech, Media, OCR">
                                @error('toolCategory') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select @error('toolStatus') is-invalid @enderror" wire:model.defer="toolStatus">
                                    <option value="active">Active</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                                @error('toolStatus') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Admin Notes</label>
                                <textarea class="form-control @error('toolNotes') is-invalid @enderror" rows="3" wire:model.defer="toolNotes" placeholder="Optional internal note or maintenance context"></textarea>
                                @error('toolNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Advanced Meta JSON</label>
                                <textarea class="form-control font-monospace @error('toolMetaJson') is-invalid @enderror" rows="6" wire:model.defer="toolMetaJson" placeholder='{"owner":"ml-team","sla":"gold"}'></textarea>
                                @error('toolMetaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetToolForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingToolId ? 'Save Changes' : 'Create Tool' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicesToolActionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveAction">
                    @csrf
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1">{{ $editingActionId ? 'Quick Edit Action' : 'Create Tool Action' }}</h5>
                            <p class="text-muted mb-0">Actions inherit the parent tool code and become billable units for pricing rules.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetActionForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Tool</label>
                                <select class="form-select @error('actionToolId') is-invalid @enderror" wire:model.defer="actionToolId" {{ $editingActionId ? 'disabled' : '' }}>
                                    <option value="">Choose tool...</option>
                                    @foreach ($this->toolOptions as $toolOption)
                                        <option value="{{ $toolOption->id }}">{{ $toolOption->name }} ({{ $toolOption->code }})</option>
                                    @endforeach
                                </select>
                                @error('actionToolId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Action Code</label>
                                <input type="text" class="form-control @error('actionCode') is-invalid @enderror" wire:model.defer="actionCode" placeholder="standard" {{ $editingActionId ? 'disabled' : '' }}>
                                @error('actionCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select @error('actionStatus') is-invalid @enderror" wire:model.defer="actionStatus">
                                    <option value="active">Active</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                                @error('actionStatus') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Action Name</label>
                                <input type="text" class="form-control @error('actionName') is-invalid @enderror" wire:model.defer="actionName" placeholder="TTS Standard">
                                @error('actionName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Default Metric Code</label>
                                <input list="tool-action-metrics" type="text" class="form-control @error('actionMetricCode') is-invalid @enderror" wire:model.defer="actionMetricCode" placeholder="character">
                                @error('actionMetricCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <datalist id="tool-action-metrics">
                                    @foreach ($metricOptions as $metricCode => $metricLabel)
                                        <option value="{{ $metricCode }}">{{ $metricLabel }}</option>
                                    @endforeach
                                </datalist>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Admin Notes</label>
                                <textarea class="form-control @error('actionNotes') is-invalid @enderror" rows="3" wire:model.defer="actionNotes"></textarea>
                                @error('actionNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Advanced Meta JSON</label>
                                <textarea class="form-control font-monospace @error('actionMetaJson') is-invalid @enderror" rows="6" wire:model.defer="actionMetaJson" placeholder='{"quality":"studio","provider":"runpod"}'></textarea>
                                @error('actionMetaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetActionForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingActionId ? 'Save Changes' : 'Create Action' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="servicesToolDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">
                        Delete <span class="fw-semibold">{{ $deleteLabel }}</span>?
                        @if ($deleteTarget === 'action')
                            Related pricing rules and plan entitlements will cascade automatically if no usage history exists.
                        @else
                            Tools can only be removed when they no longer have child actions.
                        @endif
                    </p>
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
                    if (window.__SERVICES_TOOLS_MODAL_EVENTS__) {
                        return;
                    }

                    window.__SERVICES_TOOLS_MODAL_EVENTS__ = true;

                    const modalIds = [
                        'servicesToolModal',
                        'servicesToolActionModal',
                        'servicesToolDeleteModal',
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

                    window.addEventListener('services-tools:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('services-tools:modal-hide', (event) => {
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

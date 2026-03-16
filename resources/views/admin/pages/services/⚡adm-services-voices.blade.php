<?php

use App\Support\Admin\ManagesServiceVoicesPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Services Voices | METKURD')]
class extends Component
{
    use ManagesServiceVoicesPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Service Voices & Access</h4>
                    <p class="text-muted mb-0">Voice library management plus plan-level visibility and access control.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                    <button type="button" class="btn btn-soft-primary" wire:click="openAccessCreateModal">Grant Access</button>
                    <button type="button" class="btn btn-primary" wire:click="openVoiceCreateModal">New Voice</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Voice Library</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['voices']) }}</h2>
                    <p class="text-muted mb-0">{{ number_format($this->topStats['active_voices']) }} active voices configured.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Public Voices</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['public_voices']) }}</h2>
                    <p class="text-muted mb-0">Visibility is handled per voice and can also be overridden at the plan level.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Plan Access Rows</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['access_rows']) }}</h2>
                    <p class="text-muted mb-0">Each row maps a plan to a voice with activation and visibility flags.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Filtered Voices</p>
                    <h2 class="mb-1">{{ number_format($this->voices->total()) }}</h2>
                    <p class="text-muted mb-0">Filters are resolved on the server before pagination.</p>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search voice name, code, engine, or gender...">
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
                    <label class="form-label text-muted text-uppercase fs-12">Status</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Visibility</label>
                    <select class="form-select" wire:model.live="visibilityFilter">
                        <option value="all">Public + Private</option>
                        <option value="public">Public</option>
                        <option value="private">Private</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Assignment</label>
                    <select class="form-select" wire:model.live="assignmentFilter">
                        <option value="all">Any Access State</option>
                        <option value="assigned">Assigned to Plans</option>
                        <option value="unassigned">No Plan Access Yet</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div>
                <h5 class="card-title mb-1">Voice Access Matrix</h5>
                <p class="text-muted mb-0">Expand a voice row to review and manage plan-level access without leaving the page.</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th style="width: 56px;"></th>
                            <th>Voice</th>
                            <th>Engine</th>
                            <th>Visibility</th>
                            <th>Plan Access</th>
                            <th>Status</th>
                            <th class="text-end">Admin Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->voices as $voice)
                            @php
                                $isExpanded = in_array($voice->id, $expandedVoices, true);
                                $engine = data_get($voice->meta, 'engine', 'Not set');
                                $gender = data_get($voice->meta, 'gender');
                            @endphp
                            <tr wire:key="voice-row-{{ $voice->id }}">
                                <td>
                                    <button type="button" class="btn btn-sm btn-icon btn-soft-secondary" wire:click="toggleExpandedVoice({{ $voice->id }})">
                                        <i class="ri-arrow-{{ $isExpanded ? 'down' : 'right' }}-s-line"></i>
                                    </button>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-semibold">{{ $voice->name }}</span>
                                            <span class="badge {{ $this->statusBadgeClasses((bool) $voice->is_active) }}">{{ $voice->is_active ? 'Active' : 'Maintenance' }}</span>
                                        </div>
                                        <span class="text-muted small">{{ $voice->code }}</span>
                                        @if ($gender)
                                            <span class="text-muted small">Gender: {{ ucfirst($gender) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $engine }}</td>
                                <td><span class="badge {{ $this->visibilityBadgeClasses((bool) $voice->is_public) }}">{{ $voice->is_public ? 'Public' : 'Private' }}</span></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ number_format((int) ($voice->access_count ?? 0)) }} plans</span>
                                        <span class="text-muted small">{{ number_format((int) ($voice->active_access_count ?? 0)) }} active access rows</span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm {{ $voice->is_active ? 'btn-success' : 'btn-warning' }}" wire:click="toggleVoiceStatus({{ $voice->id }})">
                                        {{ $voice->is_active ? 'Set Maintenance' : 'Restore Active' }}
                                    </button>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openAccessCreateModal({{ $voice->id }})">Grant Access</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openVoiceEditModal({{ $voice->id }})">Quick Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmVoiceDelete({{ $voice->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            @if ($isExpanded)
                                <tr wire:key="voice-access-row-{{ $voice->id }}" class="bg-light-subtle">
                                    <td colspan="7" class="p-0">
                                        <div class="p-3 border-top">
                                            <div class="d-flex align-items-center justify-content-between mb-3">
                                                <div>
                                                    <h6 class="mb-1">Plan access for {{ $voice->name }}</h6>
                                                    <p class="text-muted mb-0">Use these overrides to decide which plans can see and use this voice.</p>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-primary" wire:click="openAccessCreateModal({{ $voice->id }})">New Access Row</button>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr class="text-uppercase text-muted">
                                                            <th>Plan</th>
                                                            <th>Visibility</th>
                                                            <th>Sort</th>
                                                            <th>Status</th>
                                                            <th class="text-end">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse ($voice->planAccesses as $access)
                                                            <tr wire:key="voice-access-{{ $access->id }}">
                                                                <td>{{ $access->servicePlan?->name ?? 'Unknown Plan' }}</td>
                                                                <td><span class="badge {{ $this->visibilityBadgeClasses((bool) $access->is_public) }}">{{ $access->is_public ? 'Public' : 'Private' }}</span></td>
                                                                <td>{{ number_format((int) $access->sort_order) }}</td>
                                                                <td><span class="badge {{ $this->statusBadgeClasses((bool) $access->is_active) }}">{{ $access->is_active ? 'Active' : 'Maintenance' }}</span></td>
                                                                <td class="text-end">
                                                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="toggleAccessStatus({{ $access->id }})">{{ $access->is_active ? 'Maintenance' : 'Activate' }}</button>
                                                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openAccessEditModal({{ $access->id }})">Edit</button>
                                                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmAccessDelete({{ $access->id }})">Delete</button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        @empty
                                                            <tr>
                                                                <td colspan="5" class="text-center py-4 text-muted">No plan access rows exist for this voice yet.</td>
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
                                <td colspan="7" class="text-center py-5 text-muted">
                                    No voices matched the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->voices->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceVoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveVoice">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingVoiceId ? 'Quick Edit Voice' : 'Create Voice' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetVoiceForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Voice Code</label>
                                <input type="text" class="form-control @error('voiceCode') is-invalid @enderror" wire:model.defer="voiceCode" placeholder="liza" {{ $editingVoiceId ? 'disabled' : '' }}>
                                @error('voiceCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Voice Name</label>
                                <input type="text" class="form-control @error('voiceName') is-invalid @enderror" wire:model.defer="voiceName">
                                @error('voiceName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Sort Order</label>
                                <input type="number" min="0" class="form-control @error('voiceSortOrder') is-invalid @enderror" wire:model.defer="voiceSortOrder">
                                @error('voiceSortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Engine</label>
                                <input type="text" class="form-control" wire:model.defer="voiceEngine" placeholder="xtts">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender</label>
                                <input type="text" class="form-control" wire:model.defer="voiceGender" placeholder="female">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Visibility</label>
                                <select class="form-select" wire:model.defer="voiceVisibility">
                                    <option value="public">Public</option>
                                    <option value="private">Private</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" wire:model.defer="voiceStatus">
                                    <option value="active">Active</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Admin Notes</label>
                                <textarea class="form-control @error('voiceNotes') is-invalid @enderror" rows="3" wire:model.defer="voiceNotes"></textarea>
                                @error('voiceNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Advanced Meta JSON</label>
                                <textarea class="form-control font-monospace @error('voiceMetaJson') is-invalid @enderror" rows="6" wire:model.defer="voiceMetaJson"></textarea>
                                @error('voiceMetaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetVoiceForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingVoiceId ? 'Save Changes' : 'Create Voice' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceVoiceAccessModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveAccess">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingAccessId ? 'Edit Plan Voice Access' : 'Create Plan Voice Access' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetAccessForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Service Plan</label>
                                <select class="form-select @error('accessPlanId') is-invalid @enderror" wire:model.defer="accessPlanId">
                                    <option value="">Choose plan...</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('accessPlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Voice</label>
                                <select class="form-select @error('accessVoiceId') is-invalid @enderror" wire:model.defer="accessVoiceId">
                                    <option value="">Choose voice...</option>
                                    @foreach ($this->voiceOptions as $voiceOption)
                                        <option value="{{ $voiceOption->id }}">{{ $voiceOption->name }} ({{ $voiceOption->code }})</option>
                                    @endforeach
                                </select>
                                @error('accessVoiceId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Visibility</label>
                                <select class="form-select" wire:model.defer="accessVisibility">
                                    <option value="public">Public</option>
                                    <option value="private">Private</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" wire:model.defer="accessStatus">
                                    <option value="active">Active</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sort Order</label>
                                <input type="number" min="0" class="form-control @error('accessSortOrder') is-invalid @enderror" wire:model.defer="accessSortOrder">
                                @error('accessSortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Admin Notes</label>
                                <textarea class="form-control @error('accessNotes') is-invalid @enderror" rows="3" wire:model.defer="accessNotes"></textarea>
                                @error('accessNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Advanced Meta JSON</label>
                                <textarea class="form-control font-monospace @error('accessMetaJson') is-invalid @enderror" rows="6" wire:model.defer="accessMetaJson"></textarea>
                                @error('accessMetaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetAccessForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingAccessId ? 'Save Changes' : 'Create Access Row' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceVoiceDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Delete <span class="fw-semibold">{{ $deleteLabel }}</span>? Related access rows will be removed automatically when a voice is deleted.</p>
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
                    if (window.__SERVICES_VOICES_MODAL_EVENTS__) {
                        return;
                    }

                    window.__SERVICES_VOICES_MODAL_EVENTS__ = true;

                    const modalIds = [
                        'serviceVoiceModal',
                        'serviceVoiceAccessModal',
                        'serviceVoiceDeleteModal',
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

                    window.addEventListener('services-voices:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('services-voices:modal-hide', (event) => {
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

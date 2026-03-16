<?php

use App\Support\Admin\ManagesServiceEntitlementsPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
#[Title('Services Entitlements | METKURD')]
class extends Component
{
    use ManagesServiceEntitlementsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">Plan Entitlements</h4>
                    <p class="text-muted mb-0">Control which plans can access which tool actions, plus optional per-plan limits payloads.</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.services.pricing', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">View Pricing</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">Clear Filters</button>
                    <button type="button" class="btn btn-primary" wire:click="openEntitlementCreateModal">New Entitlement</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Entitlement Rows</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['entitlements']) }}</h2>
                    <p class="text-muted mb-0">All explicit plan-to-tool-action access decisions.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Allowed</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['allowed_entitlements']) }}</h2>
                    <p class="text-muted mb-0">Rows that currently grant access.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Blocked</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['blocked_entitlements']) }}</h2>
                    <p class="text-muted mb-0">Rows that explicitly deny access.</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">Current Scope</p>
                    <h2 class="mb-1">{{ number_format($this->entitlements->total()) }}</h2>
                    <p class="text-muted mb-0">Entitlement rows that match the active filters below.</p>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="Search plan or action...">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Plan</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">All Plans</option>
                        @foreach ($this->planOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">Tool Action</label>
                    <select class="form-select" wire:model.live="actionFilter">
                        <option value="all">All Actions</option>
                        @foreach ($this->actionOptions as $action)
                            <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">State</label>
                    <select class="form-select" wire:model.live="entitlementFilter">
                        <option value="all">All Rows</option>
                        <option value="allowed">Allowed Only</option>
                        <option value="blocked">Blocked Only</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div>
                <h5 class="card-title mb-1">Plan Entitlements</h5>
                <p class="text-muted mb-0">Use entitlements to grant or block tool actions per plan, with optional limits JSON.</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>Plan</th>
                            <th>Tool Action</th>
                            <th>Allowed</th>
                            <th>Limits</th>
                            <th>Updated</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->entitlements as $entitlement)
                            <tr wire:key="entitlement-row-{{ $entitlement->id }}">
                                <td>{{ $entitlement->servicePlan?->name ?? 'Unknown Plan' }}</td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $entitlement->toolAction?->name ?? 'Unknown Action' }}</span>
                                        <span class="text-muted small">{{ $entitlement->toolAction?->full_code ?? 'n/a' }}</span>
                                    </div>
                                </td>
                                <td><span class="badge {{ $this->statusBadgeClasses((bool) $entitlement->allowed) }}">{{ $entitlement->allowed ? 'Allowed' : 'Blocked' }}</span></td>
                                <td>
                                    @if ($entitlement->limits)
                                        <code>{{ json_encode($entitlement->limits) }}</code>
                                    @else
                                        <span class="text-muted">No limits</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $entitlement->updated_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="toggleEntitlementAllowed({{ $entitlement->id }})">{{ $entitlement->allowed ? 'Block' : 'Allow' }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openEntitlementEditModal({{ $entitlement->id }})">Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmEntitlementDelete({{ $entitlement->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">No entitlements matched the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->entitlements->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceEntitlementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveEntitlement">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingEntitlementId ? 'Edit Plan Entitlement' : 'Create Plan Entitlement' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetEntitlementForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Service Plan</label>
                                <select class="form-select @error('entitlementServicePlanId') is-invalid @enderror" wire:model.defer="entitlementServicePlanId">
                                    <option value="">Choose plan...</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('entitlementServicePlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tool Action</label>
                                <select class="form-select @error('entitlementToolActionId') is-invalid @enderror" wire:model.defer="entitlementToolActionId">
                                    <option value="">Choose action...</option>
                                    @foreach ($this->actionOptions as $action)
                                        <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                                    @endforeach
                                </select>
                                @error('entitlementToolActionId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Access</label>
                                <select class="form-select" wire:model.defer="entitlementAllowed">
                                    <option value="allowed">Allowed</option>
                                    <option value="blocked">Blocked</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Limits JSON</label>
                                <textarea class="form-control font-monospace @error('entitlementLimitsJson') is-invalid @enderror" rows="4" wire:model.defer="entitlementLimitsJson"></textarea>
                                @error('entitlementLimitsJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetEntitlementForm">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingEntitlementId ? 'Save Changes' : 'Create Entitlement' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceEntitlementDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Delete <span class="fw-semibold">{{ $deleteLabel }}</span>? Historical usage rows keep their original recorded values.</p>
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
                    if (window.__SERVICES_ENTITLEMENTS_MODAL_EVENTS__) {
                        return;
                    }

                    window.__SERVICES_ENTITLEMENTS_MODAL_EVENTS__ = true;

                    const modalIds = [
                        'serviceEntitlementModal',
                        'serviceEntitlementDeleteModal',
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

                    window.addEventListener('services-entitlements:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('services-entitlements:modal-hide', (event) => {
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

<?php

use App\Support\Admin\ManagesServiceEntitlementsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesServiceEntitlementsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Services Entitlements') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Plan Entitlements') }}</h4>
                    <p class="text-muted mb-0">{{ __('Control tool access per plan and per channel, while keeping Public API tool scopes aligned with plan API settings.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.services.pricing', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">{{ __('View Pricing') }}</a>
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openEntitlementCreateModal">{{ __('New Entitlement') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Entitlement Rows') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['entitlements']) }}</h2>
                    <p class="text-muted mb-0">{{ __('All explicit plan-to-tool-action access decisions.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Allowed') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['allowed_entitlements']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Rows that currently grant access.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Blocked') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['blocked_entitlements']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Rows that explicitly deny access.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Current Scope') }}</p>
                    <h2 class="mb-1">{{ number_format($this->entitlements->total()) }}</h2>
                    <p class="text-muted mb-0">{{ __('Entitlement rows that match the active filters below.') }}</p>
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
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search plan or action...') }}">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">{{ __('All Plans') }}</option>
                        @foreach ($this->planOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-3 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Tool Action') }}</label>
                    <select class="form-select" wire:model.live="actionFilter">
                        <option value="all">{{ __('All Actions') }}</option>
                        @foreach ($this->actionOptions as $action)
                            <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('State') }}</label>
                    <select class="form-select" wire:model.live="entitlementFilter">
                        <option value="all">{{ __('All Rows') }}</option>
                        <option value="allowed">{{ __('Allowed Only') }}</option>
                        <option value="blocked">{{ __('Blocked Only') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Channel') }}</label>
                    <select class="form-select" wire:model.live="channelFilter">
                        <option value="all">{{ __('All Channels') }}</option>
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
                <h5 class="card-title mb-1">{{ __('Plan Entitlements') }}</h5>
                <p class="text-muted mb-0">{{ __('Use entitlements to grant or block tool actions per plan, with optional limits JSON.') }}</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Tool Action') }}</th>
                            <th>{{ __('Channel') }}</th>
                            <th>{{ __('Allowed') }}</th>
                            <th>{{ __('Limits') }}</th>
                            <th>{{ __('Updated') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->entitlements as $entitlement)
                            <tr wire:key="entitlement-row-{{ $entitlement->id }}">
                                <td>{{ $entitlement->servicePlan?->name ?? __('Unknown Plan') }}</td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $entitlement->toolAction?->name ?? __('Unknown Action') }}</span>
                                        <span class="text-muted small">{{ $entitlement->toolAction?->full_code ?? __('n/a') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge {{ $this->channelBadgeClasses((string) ($entitlement->entitlement_channel ?? 'app')) }}">
                                        {{ strtoupper((string) ($entitlement->entitlement_channel ?? 'app')) }}
                                    </span>
                                </td>
                                <td><span class="badge {{ $this->statusBadgeClasses((bool) $entitlement->allowed) }}">{{ $entitlement->allowed ? __('Allowed') : __('Blocked') }}</span></td>
                                <td>
                                    @if ($entitlement->limits)
                                        <code>{{ json_encode($entitlement->limits) }}</code>
                                    @else
                                        <span class="text-muted">{{ __('No limits') }}</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $entitlement->updated_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="toggleEntitlementAllowed({{ $entitlement->id }})">{{ $entitlement->allowed ? __('Block') : __('Allow') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openEntitlementEditModal({{ $entitlement->id }})">{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmEntitlementDelete({{ $entitlement->id }})">{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">{{ __('No entitlements matched the current filters.') }}</td>
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
                        <h5 class="modal-title">{{ $editingEntitlementId ? __('Edit Plan Entitlement') : __('Create Plan Entitlement') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetEntitlementForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Service Plan') }}</label>
                                <select class="form-select @error('entitlementServicePlanId') is-invalid @enderror" wire:model.defer="entitlementServicePlanId">
                                    <option value="">{{ __('Choose plan...') }}</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('entitlementServicePlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Tool Action') }}</label>
                                <select class="form-select @error('entitlementToolActionId') is-invalid @enderror" wire:model.defer="entitlementToolActionId">
                                    <option value="">{{ __('Choose action...') }}</option>
                                    @foreach ($this->actionOptions as $action)
                                        <option value="{{ $action->id }}">{{ $action->full_code }}</option>
                                    @endforeach
                                </select>
                                @error('entitlementToolActionId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Entitlement Channel') }}</label>
                                <select class="form-select @error('entitlementChannel') is-invalid @enderror" wire:model.defer="entitlementChannel">
                                    <option value="app">{{ __('App Dashboard') }}</option>
                                    <option value="api">{{ __('Public API') }}</option>
                                    <option value="mobile">{{ __('Mobile API') }}</option>
                                </select>
                                <div class="form-text">{{ __('API rows also align known Public API tool scopes on the plan record.') }}</div>
                                @error('entitlementChannel') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Access') }}</label>
                                <select class="form-select" wire:model.defer="entitlementAllowed">
                                    <option value="allowed">{{ __('Allowed') }}</option>
                                    <option value="blocked">{{ __('Blocked') }}</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">{{ __('Limits JSON') }}</label>
                                <textarea class="form-control font-monospace @error('entitlementLimitsJson') is-invalid @enderror" rows="4" wire:model.defer="entitlementLimitsJson"></textarea>
                                @error('entitlementLimitsJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetEntitlementForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingEntitlementId ? __('Save Changes') : __('Create Entitlement') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceEntitlementDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Confirm Delete') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deleteLabel }}</span>? {{ __('Historical usage rows keep their original recorded values.') }}</p>
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

<?php

use App\Support\Admin\ManagesLandingContactSettingsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesLandingContactSettingsPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Landing Contact & Social') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <x-admin-capability-notice :capabilities="['admin.catalog']" />
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Landing Contact & Social Settings') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage contact/company info and which social links are visible on landing/contact pages.') }}</p>
                </div>
                <div class="page-title-right">
                    <button type="button" class="btn btn-primary" wire:click="saveContactSettings" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                        <i class="ri-save-line align-bottom me-1"></i>
                        {{ __('Save Contact Settings') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">{{ __('Contact + Company') }}</h5>
            <p class="text-muted mb-0">{{ __('This keeps contact page business data editable while preserving the existing form logic.') }}</p>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Primary Support Email') }}</label>
                    <input type="email" class="form-control @error('supportEmail') is-invalid @enderror" wire:model.defer="supportEmail" placeholder="support@metkurd.ai">
                    @error('supportEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="button" class="btn btn-primary w-100" wire:click="saveContactSettings" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                        {{ __('Save Contact/Company Info') }}
                    </button>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Support Lines (one per line)') }}</label>
                    <textarea class="form-control @error('supportLinesText') is-invalid @enderror" rows="6" wire:model.defer="supportLinesText"></textarea>
                    @error('supportLinesText') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Company Lines (one per line)') }}</label>
                    <textarea class="form-control @error('companyLinesText') is-invalid @enderror" rows="6" wire:model.defer="companyLinesText"></textarea>
                    @error('companyLinesText') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="card-title mb-1">{{ __('Social Links') }}</h5>
                    <p class="text-muted mb-0">{{ __('Inactive links are hidden from guest pages.') }}</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetSocialFilters">
                        {{ __('Clear Filters') }}
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="openSocialCreateModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                        <i class="ri-add-line align-bottom me-1"></i>
                        {{ __('New Social Link') }}
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-3 align-items-end">
                <div class="col-xl-8">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.300ms="socialSearch" placeholder="{{ __('Search platform or URL...') }}">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="socialStatusFilter">
                        <option value="all">{{ __('All Statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Platform') }}</th>
                            <th>{{ __('URL') }}</th>
                            <th>{{ __('Icon') }}</th>
                            <th>{{ __('Sort') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->socialLinks as $link)
                            <tr wire:key="landing-social-link-{{ $link->id }}">
                                <td class="fw-semibold">{{ $link->platform }}</td>
                                <td><a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($link->url, 80) }}</a></td>
                                <td><code>{{ $link->icon_class ?: 'bi bi-link-45deg' }}</code></td>
                                <td>{{ number_format((int) $link->sort_order) }}</td>
                                <td>
                                    <span class="badge {{ $link->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $link->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="toggleSocialStatus({{ $link->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                                            {{ $link->is_active ? __('Disable') : __('Enable') }}
                                        </button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openSocialEditModal({{ $link->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmSocialDelete({{ $link->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">{{ __('No social links found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->socialLinks->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="landingSocialLinkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveSocialLink">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingSocialLinkId ? __('Edit Social Link') : __('Create Social Link') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetSocialForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Platform Name') }}</label>
                                <input type="text" class="form-control @error('socialPlatform') is-invalid @enderror" wire:model.defer="socialPlatform" placeholder="YouTube">
                                @error('socialPlatform') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Icon Class') }}</label>
                                <input type="text" class="form-control @error('socialIconClass') is-invalid @enderror" wire:model.defer="socialIconClass" placeholder="bi bi-youtube">
                                @error('socialIconClass') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('URL') }}</label>
                                <input type="url" class="form-control @error('socialUrl') is-invalid @enderror" wire:model.defer="socialUrl" placeholder="https://...">
                                @error('socialUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('socialSortOrder') is-invalid @enderror" wire:model.defer="socialSortOrder">
                                @error('socialSortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Status') }}</label>
                                <select class="form-select @error('socialStatus') is-invalid @enderror" wire:model.defer="socialStatus">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                </select>
                                @error('socialStatus') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetSocialForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingSocialLinkId ? __('Update') : __('Create') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="landingSocialDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Delete Social Link') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetSocialDeleteState"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">{{ __('Delete ":platform"? This cannot be undone.', ['platform' => $socialLinkDeleteLabel]) }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetSocialDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" wire:click="performSocialDelete" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script data-navigate-once>
                (() => {
                    if (window.__LANDING_CONTACT_MODAL_EVENTS__) {
                        return;
                    }

                    window.__LANDING_CONTACT_MODAL_EVENTS__ = true;

                    const modalIds = ['landingSocialLinkModal', 'landingSocialDeleteModal'];

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

                    window.addEventListener('landing-contact:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('landing-contact:modal-hide', (event) => {
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


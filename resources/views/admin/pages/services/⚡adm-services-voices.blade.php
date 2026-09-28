<?php

use App\Support\Admin\ManagesServiceVoicesPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesServiceVoicesPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Services Voices') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid admin-service-workspace">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-capability-notice :capabilities="['admin.catalog', 'admin.pricing']" />
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Service Voices & Access') }}</h4>
                    <p class="text-muted mb-0">{{ __('Voice library management plus plan-level visibility and access control.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                    <button type="button" class="btn btn-soft-primary" wire:click="openAccessCreateModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Grant Access') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="openVoiceCreateModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('admin_ux.add_voice') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Voice Library') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['voices']) }}</h2>
                    <p class="text-muted mb-0">{{ __(':count active voices configured.', ['count' => number_format($this->topStats['active_voices'])]) }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Public Voices') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['public_voices']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Visibility is handled per voice and can also be overridden at the plan level.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Plan Access Rows') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['access_rows']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Each row maps a plan to a voice with activation and visibility flags.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Filtered Voices') }}</p>
                    <h2 class="mb-1">{{ number_format($this->voices->total()) }}</h2>
                    <p class="text-muted mb-0">{{ __('Filters are resolved on the server before pagination.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-voices-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search voice name, code, engine, or gender...') }}" id="admin-field-adm-services-voices-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-voices-2">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter" id="admin-field-adm-services-voices-2">
                        <option value="all">{{ __('All Plans') }}</option>
                        @foreach ($this->planOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-voices-3">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-services-voices-3">
                        <option value="all">{{ __('All Statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="maintenance">{{ __('Maintenance') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-voices-4">{{ __('Visibility') }}</label>
                    <select class="form-select" wire:model.live="visibilityFilter" id="admin-field-adm-services-voices-4">
                        <option value="all">{{ __('Public + Private') }}</option>
                        <option value="public">{{ __('Public') }}</option>
                        <option value="private">{{ __('Private') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-services-voices-5">{{ __('Assignment') }}</label>
                    <select class="form-select" wire:model.live="assignmentFilter" id="admin-field-adm-services-voices-5">
                        <option value="all">{{ __('Any Access State') }}</option>
                        <option value="assigned">{{ __('Assigned to Plans') }}</option>
                        <option value="unassigned">{{ __('No Plan Access Yet') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div>
                <h5 class="card-title mb-1">{{ __('Voice Access Matrix') }}</h5>
                <p class="text-muted mb-0">{{ __('admin_service.voice_help') }}</p>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th style="width: 56px;"></th>
                            <th>{{ __('Voice') }}</th>
                            <th>{{ __('Engine') }}</th>
                            <th>{{ __('Visibility') }}</th>
                            <th>{{ __('Plan Access') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Admin Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $voiceSummaries = app(\App\Support\Admin\AdminServiceWorkspace::class)->voiceSummaries($this->voices->getCollection());
                        @endphp
                        @forelse ($this->voices as $voice)
                            @php
                                $isExpanded = in_array($voice->id, $expandedVoices, true);
                                $engine = data_get($voice->meta, 'engine', __('Not set'));
                                $gender = data_get($voice->meta, 'gender');
                                $voiceSummary = $voiceSummaries[$voice->id];
                            @endphp
                            <tr wire:key="voice-row-{{ $voice->id }}">
                                <td>
                                    <button type="button" class="btn btn-sm btn-icon btn-soft-secondary" wire:click="toggleExpandedVoice({{ $voice->id }})" aria-label="{{ __('admin_p3.expand') }}">
                                        <i class="ri-arrow-{{ $isExpanded ? 'down' : 'right' }}-s-line"></i>
                                    </button>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-semibold">{{ $voice->name }}</span>
                                            <span class="badge {{ $this->statusBadgeClasses((bool) $voice->is_active) }}">{{ $voice->is_active ? __('Active') : __('Maintenance') }}</span>
                                        </div>
                                        <span class="text-muted small">{{ __('admin_service.voice_id') }}: <bdi>{{ $voice->code }}</bdi></span><small class="text-muted">{{ __('admin_service.'.($voiceSummary['preview'] ? 'preview_configured' : 'preview_missing')) }}</small>
                                        @if ($gender)
                                            <span class="text-muted small">{{ __('Gender: :value', ['value' => ucfirst($gender)]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td><bdi>{{ $engine }}</bdi>@if($voiceSummary['omni'])<small class="d-block"><bdi>Apollo / Zeta</bdi></small>@endif</td>
                                <td><span class="badge {{ $this->visibilityBadgeClasses((bool) $voice->is_public) }}">{{ $voice->is_public ? __('Public') : __('Private') }}</span></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="small" dir="auto">{{ implode(' · ', $voiceSummary['plans']) ?: __('admin_service.no_voice_plans') }}</span>
                                        <span class="text-muted small">{{ __(':count active access rows', ['count' => number_format((int) ($voice->active_access_count ?? 0))]) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm {{ $voice->is_active ? 'btn-success' : 'btn-warning' }}" data-admin-method="toggleVoiceStatus" data-admin-args="{{ json_encode([$voice->id]) }}" data-admin-impact="{{ __('admin_p3.catalog_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                                        {{ $voice->is_active ? __('Set Maintenance') : __('Restore Active') }}
                                    </button>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openAccessCreateModal({{ $voice->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Grant Access') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openVoiceEditModal({{ $voice->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmVoiceDelete({{ $voice->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            @if ($isExpanded)
                                <tr wire:key="voice-access-row-{{ $voice->id }}" class="bg-light-subtle">
                                    <td colspan="7" class="p-0">
                                        <div class="p-3 border-top">
                                            <div class="d-flex align-items-center justify-content-between mb-3">
                                                <div>
                                                    <h6 class="mb-1">{{ __('Plan access for :name', ['name' => $voice->name]) }}</h6>
                                                    <p class="text-muted mb-0">{{ __('Use these overrides to decide which plans can see and use this voice.') }}</p>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-primary" wire:click="openAccessCreateModal({{ $voice->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('New Access Row') }}</button>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr class="text-uppercase text-muted">
                                                            <th>{{ __('Plan') }}</th>
                                                            <th>{{ __('Visibility') }}</th>
                                                            <th>{{ __('Sort') }}</th>
                                                            <th>{{ __('Status') }}</th>
                                                            <th class="text-end">{{ __('Actions') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse ($voice->planAccesses as $access)
                                                            <tr wire:key="voice-access-{{ $access->id }}">
                                                                <td>{{ $access->servicePlan?->name ?? __('Unknown Plan') }}</td>
                                                                <td><span class="badge {{ $this->visibilityBadgeClasses((bool) $access->is_public) }}">{{ $access->is_public ? __('Public') : __('Private') }}</span></td>
                                                                <td>{{ number_format((int) $access->sort_order) }}</td>
                                                                <td><span class="badge {{ $this->statusBadgeClasses((bool) $access->is_active) }}">{{ $access->is_active ? __('Active') : __('Maintenance') }}</span></td>
                                                                <td class="text-end">
                                                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                                                        <button type="button" class="btn btn-sm btn-soft-success" data-admin-method="toggleAccessStatus" data-admin-args="{{ json_encode([$access->id]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ $access->is_active ? __('Maintenance') : __('Activate') }}</button>
                                                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openAccessEditModal({{ $access->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Edit') }}</button>
                                                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmAccessDelete({{ $access->id }})" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Delete') }}</button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        @empty
                                                            <tr>
                                                                <td colspan="5" class="text-center py-4 text-muted">{{ __('No plan access rows exist for this voice yet.') }}</td>
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
                                    {{ __('No voices matched the current filters.') }}
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
                <form data-admin-method="saveVoice" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.catalog_effect') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.catalog')) disabled @endif>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingVoiceId ? __('Quick Edit Voice') : __('Create Voice') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetVoiceForm"></button>
                    </div>
                    <div class="modal-body">
                        <x-admin-validation-summary />
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-voices-6">{{ __('Voice Code') }}</label>
                                <input type="text" class="form-control @error('voiceCode') is-invalid @enderror" wire:model.defer="voiceCode" placeholder="liza" {{ $editingVoiceId ? 'disabled' : '' }} id="admin-field-adm-services-voices-6" dir="ltr" data-admin-review>
                                @error('voiceCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="admin-field-adm-services-voices-7">{{ __('Voice Name') }}</label>
                                <input type="text" class="form-control @error('voiceName') is-invalid @enderror" wire:model.defer="voiceName" data-admin-review id="admin-field-adm-services-voices-7" dir="auto">
                                @error('voiceName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-services-voices-8">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('voiceSortOrder') is-invalid @enderror" wire:model.defer="voiceSortOrder" id="admin-field-adm-services-voices-8" data-admin-review>
                                @error('voiceSortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-voices-9">{{ __('Engine') }}</label>
                                <input type="text" class="form-control" wire:model.defer="voiceEngine" placeholder="{{ __('xtts') }}" id="admin-field-adm-services-voices-9" data-admin-review>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-voices-10">{{ __('Gender') }}</label>
                                <input type="text" class="form-control" wire:model.defer="voiceGender" placeholder="{{ __('female') }}" id="admin-field-adm-services-voices-10" data-admin-review>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="admin-field-adm-services-voices-11">{{ __('Visibility') }}</label>
                                <select class="form-select" wire:model.defer="voiceVisibility" id="admin-field-adm-services-voices-11" data-admin-review>
                                    <option value="public">{{ __('Public') }}</option>
                                    <option value="private">{{ __('Private') }}</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="admin-field-adm-services-voices-12">{{ __('Status') }}</label>
                                <select class="form-select" wire:model.defer="voiceStatus" id="admin-field-adm-services-voices-12" data-admin-review>
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="maintenance">{{ __('Maintenance') }}</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="admin-field-adm-services-voices-13">{{ __('Admin Notes') }}</label>
                                <textarea class="form-control @error('voiceNotes') is-invalid @enderror" rows="3" wire:model.defer="voiceNotes" id="admin-field-adm-services-voices-13" dir="auto" data-admin-review></textarea>
                                @error('voiceNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Advanced Meta JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea class="form-control font-monospace @error('voiceMetaJson') is-invalid @enderror" rows="6" wire:model.defer="voiceMetaJson" dir="ltr"></textarea></details>
                                @error('voiceMetaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetVoiceForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingVoiceId ? __('Save Changes') : __('Create Voice') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceVoiceAccessModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form data-admin-method="saveAccess" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingAccessId ? __('Edit Plan Voice Access') : __('Create Plan Voice Access') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetAccessForm"></button>
                    </div>
                    <div class="modal-body">
                        <x-admin-validation-summary />
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-services-voices-14">{{ __('Service Plan') }}</label>
                                <select class="form-select @error('accessPlanId') is-invalid @enderror" wire:model.defer="accessPlanId" id="admin-field-adm-services-voices-14" data-admin-review>
                                    <option value="">{{ __('Choose plan...') }}</option>
                                    @foreach ($this->planOptions as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                                @error('accessPlanId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-services-voices-15">{{ __('Voice') }}</label>
                                <select class="form-select @error('accessVoiceId') is-invalid @enderror" wire:model.defer="accessVoiceId" id="admin-field-adm-services-voices-15" data-admin-review>
                                    <option value="">{{ __('Choose voice...') }}</option>
                                    @foreach ($this->voiceOptions as $voiceOption)
                                        <option value="{{ $voiceOption->id }}">{{ $voiceOption->name }} ({{ $voiceOption->code }})</option>
                                    @endforeach
                                </select>
                                @error('accessVoiceId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-voices-16">{{ __('Visibility') }}</label>
                                <select class="form-select" wire:model.defer="accessVisibility" id="admin-field-adm-services-voices-16" data-admin-review>
                                    <option value="public">{{ __('Public') }}</option>
                                    <option value="private">{{ __('Private') }}</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-voices-17">{{ __('Status') }}</label>
                                <select class="form-select" wire:model.defer="accessStatus" id="admin-field-adm-services-voices-17" data-admin-review>
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="maintenance">{{ __('Maintenance') }}</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-services-voices-18">{{ __('Sort Order') }}</label>
                                <input type="number" min="0" class="form-control @error('accessSortOrder') is-invalid @enderror" wire:model.defer="accessSortOrder" id="admin-field-adm-services-voices-18" data-admin-review>
                                @error('accessSortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="admin-field-adm-services-voices-19">{{ __('Admin Notes') }}</label>
                                <textarea class="form-control @error('accessNotes') is-invalid @enderror" rows="3" wire:model.defer="accessNotes" id="admin-field-adm-services-voices-19" dir="auto" data-admin-review></textarea>
                                @error('accessNotes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Advanced Meta JSON') }}</label>
                                <details><summary>{{ __('admin_p3.advanced') }}</summary><textarea class="form-control font-monospace @error('accessMetaJson') is-invalid @enderror" rows="6" wire:model.defer="accessMetaJson" dir="ltr"></textarea></details>
                                @error('accessMetaJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetAccessForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingAccessId ? __('Save Changes') : __('Create Access Row') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="serviceVoiceDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Confirm Delete') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button>
                </div>
                <div class="modal-body">
                        <x-admin-validation-summary />
                    <p class="mb-0">{{ __('Delete') }} <span class="fw-semibold">{{ $deleteLabel }}</span>? {{ __('Related access rows will be removed automatically when a voice is deleted.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-admin-target="{{ $deleteLabel }}" data-admin-method="performDelete" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.delete_effect') }}" @if(! \App\Support\Admin\AdminUiAccess::can(($deleteTarget === 'access' ? 'admin.pricing' : 'admin.catalog'))) disabled @endif>{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    </div>


</div>

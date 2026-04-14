<?php

use App\Support\Admin\ManagesLandingTranslationsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesLandingTranslationsPage;
};
?>

<x-slot:title>{{ __('Landing Translations') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Landing Translation Manager') }}</h4>
                    <p class="text-muted mb-0">{{ __('Edit EN/AR/KU values for stable landing keys and save directly to resources/lang/landing/*.json.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">
                        <i class="ri-filter-off-line align-bottom me-1"></i>
                        {{ __('Clear Filters') }}
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="saveTranslations">
                        <i class="ri-save-line align-bottom me-1"></i>
                        {{ __('Save Translations') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-8">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input
                            type="text"
                            class="form-control"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search by key or translated value...') }}"
                        >
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Section') }}</label>
                    <select class="form-select" wire:model.live="sectionFilter">
                        <option value="all">{{ __('All Sections') }}</option>
                        @foreach($this->sectionOptions as $section)
                            <option value="{{ $section }}">{{ ucfirst($section) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="card-title mb-1">{{ __('Landing Keys') }}</h5>
                    <p class="text-muted mb-0">{{ __('Rows: :count', ['count' => number_format(count($this->filteredRows))]) }}</p>
                </div>
                <button type="button" class="btn btn-primary" wire:click="saveTranslations">
                    <i class="ri-save-line align-bottom me-1"></i>
                    {{ __('Save All') }}
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-top mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th style="min-width: 320px;">{{ __('Key') }}</th>
                            <th style="min-width: 360px;">{{ __('English (EN)') }}</th>
                            <th style="min-width: 360px;">{{ __('Arabic (AR)') }}</th>
                            <th style="min-width: 360px;">{{ __('Kurdish (KU)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->filteredRows as $row)
                            <tr wire:key="landing-translation-row-{{ $row['id'] }}">
                                <td>
                                    <div class="d-flex flex-column gap-2">
                                        <code>{{ $row['key'] }}</code>
                                        <span class="badge bg-light text-body" style="width:fit-content;">{{ ucfirst($row['section']) }}</span>
                                        @if(($row['default'] ?? '') !== '')
                                            <small class="text-muted">{{ __('Default: :value', ['value' => \Illuminate\Support\Str::limit($row['default'], 120)]) }}</small>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <textarea
                                        class="form-control"
                                        rows="3"
                                        wire:model.defer="translations.{{ $row['id'] }}.en"
                                        placeholder="{{ __('English value') }}"
                                    ></textarea>
                                </td>
                                <td>
                                    <textarea
                                        class="form-control"
                                        rows="3"
                                        wire:model.defer="translations.{{ $row['id'] }}.ar"
                                        placeholder="{{ __('Arabic value') }}"
                                    ></textarea>
                                </td>
                                <td>
                                    <textarea
                                        class="form-control"
                                        rows="3"
                                        wire:model.defer="translations.{{ $row['id'] }}.ku"
                                        placeholder="{{ __('Kurdish value') }}"
                                    ></textarea>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">{{ __('No translation rows matched this filter.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


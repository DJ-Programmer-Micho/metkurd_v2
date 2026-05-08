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

<x-slot:title>{{ __('Landing Translations') }} | {{ __('MetKurd AI') }}</x-slot:title>

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
                            <option value="{{ $section }}">{{ $this->sectionLabel($section) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    @php
        $filteredRows = $this->filteredRows;
        $groupedRows = collect($filteredRows)->groupBy('section');
        $orderedSections = array_values(array_unique(array_merge(
            $this->sectionOptions,
            $groupedRows->keys()->all()
        )));
        $isLongField = static function (array $row): bool {
            $key = (string) ($row['key'] ?? '');
            $default = (string) ($row['default'] ?? '');

            if (mb_strlen($default) > 90) {
                return true;
            }

            return preg_match('/(\\.copy$|\\.lead$|\\.description$|\\.meta\\.description$|\\.meta\\.keywords$|\\.one_paragraph$|\\.title_html$|\\.summary$|\\.subject$)/', $key) === 1;
        };
    @endphp

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="card-title mb-1">{{ __('Landing Keys') }}</h5>
                    <p class="text-muted mb-0">{{ __('Rows: :count', ['count' => number_format(count($filteredRows))]) }}</p>
                </div>
                <button type="button" class="btn btn-primary" wire:click="saveTranslations">
                    <i class="ri-save-line align-bottom me-1"></i>
                    {{ __('Save All') }}
                </button>
            </div>
        </div>
    </div>

    @if(count($filteredRows) === 0)
        <div class="card">
            <div class="card-body text-center py-5 text-muted">{{ __('No translation rows matched this filter.') }}</div>
        </div>
    @endif

    @foreach($orderedSections as $section)
        @php
            $rows = $groupedRows->get($section, collect())->values()->all();
        @endphp

        @if(count($rows) === 0)
            @continue
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h5 class="card-title mb-0">{{ $this->sectionLabel($section) }}</h5>
                <span class="badge bg-light text-body">{{ __('Rows: :count', ['count' => number_format(count($rows))]) }}</span>
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
                            @foreach($rows as $row)
                                @php
                                    $useTextarea = $isLongField($row);
                                @endphp
                                <tr wire:key="landing-translation-row-{{ $row['id'] }}">
                                    <td>
                                        <div class="d-flex flex-column gap-2">
                                            <code>{{ $row['key'] }}</code>
                                            @if(($row['default'] ?? '') !== '')
                                                <small class="text-muted">{{ __('Default: :value', ['value' => \Illuminate\Support\Str::limit($row['default'], 140)]) }}</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @if($useTextarea)
                                            <textarea
                                                class="form-control"
                                                rows="3"
                                                wire:model.defer="translations.{{ $row['id'] }}.en"
                                                placeholder="{{ __('English value') }}"
                                            ></textarea>
                                        @else
                                            <input
                                                type="text"
                                                class="form-control"
                                                wire:model.defer="translations.{{ $row['id'] }}.en"
                                                placeholder="{{ __('English value') }}"
                                            >
                                        @endif
                                    </td>
                                    <td>
                                        @if($useTextarea)
                                            <textarea
                                                class="form-control"
                                                rows="3"
                                                dir="rtl"
                                                wire:model.defer="translations.{{ $row['id'] }}.ar"
                                                placeholder="{{ __('Arabic value') }}"
                                            ></textarea>
                                        @else
                                            <input
                                                type="text"
                                                class="form-control"
                                                dir="rtl"
                                                wire:model.defer="translations.{{ $row['id'] }}.ar"
                                                placeholder="{{ __('Arabic value') }}"
                                            >
                                        @endif
                                    </td>
                                    <td>
                                        @if($useTextarea)
                                            <textarea
                                                class="form-control"
                                                rows="3"
                                                dir="rtl"
                                                wire:model.defer="translations.{{ $row['id'] }}.ku"
                                                placeholder="{{ __('Kurdish value') }}"
                                            ></textarea>
                                        @else
                                            <input
                                                type="text"
                                                class="form-control"
                                                dir="rtl"
                                                wire:model.defer="translations.{{ $row['id'] }}.ku"
                                                placeholder="{{ __('Kurdish value') }}"
                                            >
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

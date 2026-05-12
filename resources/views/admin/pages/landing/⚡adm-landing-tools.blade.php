<?php

use App\Support\Admin\ManagesLandingToolsPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesLandingToolsPage;
    use WithPagination;
    use WithFileUploads;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Landing Tools CMS') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $landingMedia = app(\App\Support\Landing\LandingMediaStorage::class);
@endphp

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1">{{ __('Landing Tool Pages') }}</h4>
                <p class="text-muted mb-0">{{ __('Dynamic /tools/{slug} pages on one shared template.') }}</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                <button type="button" class="btn btn-soft-info" wire:click="importDefaultTools">{{ __('Import Default Tools') }}</button>
                <button type="button" class="btn btn-primary" wire:click="openToolCreateModal">{{ __('New Tool Page') }}</button>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-xl-8">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                    <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search slug or title...') }}">
                </div>
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">{{ __('All') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Slug') }}</th>
                            <th>{{ __('EN Title') }}</th>
                            <th>{{ __('Demo') }}</th>
                            <th>{{ __('Square Image') }}</th>
                            <th>{{ __('Sort') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->toolPages as $toolPage)
                            <tr wire:key="landing-tool-page-{{ $toolPage->id }}">
                                <td class="fw-semibold">{{ $toolPage->slug }}</td>
                                <td>{{ data_get($toolPage->content, 'en.title', $toolPage->slug) }}</td>
                                <td>
                                    @php($rowDemoType = $toolPage->demo_type ?: data_get($toolPage->content, '_demo.type'))
                                    @if($rowDemoType)
                                        <span class="badge bg-info-subtle text-info">{{ $rowDemoType }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if($toolPage->square_image_path)
                                        @php($squareThumbUrl = $landingMedia->publicUrl($toolPage->square_image_path))
                                        @if($squareThumbUrl)
                                            <img
                                                src="{{ $squareThumbUrl }}"
                                                alt="{{ data_get($toolPage->content, 'en.title', $toolPage->slug) }}"
                                                class="rounded"
                                                style="width:40px; height:40px; object-fit:cover;"
                                            >
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary">
                                                {{ strtoupper(\Illuminate\Support\Str::substr((string) data_get($toolPage->content, 'en.title', $toolPage->slug), 0, 1)) }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">
                                            {{ strtoupper(\Illuminate\Support\Str::substr((string) data_get($toolPage->content, 'en.title', $toolPage->slug), 0, 1)) }}
                                        </span>
                                    @endif
                                </td>
                                <td>{{ $toolPage->sort_order }}</td>
                                <td>
                                    <span class="badge {{ $toolPage->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $toolPage->is_active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-success" wire:click="toggleToolPageStatus({{ $toolPage->id }})">{{ $toolPage->is_active ? __('Disable') : __('Enable') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="openToolEditModal({{ $toolPage->id }})">{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="confirmToolPageDelete({{ $toolPage->id }})">{{ __('Delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center py-5 text-muted">{{ __('No tool pages found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->toolPages->onEachSide(1)->links() }}
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="landingToolPageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form wire:submit.prevent="saveToolPage">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingToolPageId ? __('Edit Landing Tool Page') : __('Create Landing Tool Page') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetToolForm"></button>
                    </div>
                    <div class="modal-body" style="max-height: calc(100vh - 220px); overflow-y: auto;">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Slug') }}</label>
                                <input type="text" class="form-control @error('slug') is-invalid @enderror" wire:model.defer="slug" placeholder="tts">
                                @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Tool Square Image') }}</label>
                                <input type="file" class="form-control @error('squareImageUpload') is-invalid @enderror" wire:model="squareImageUpload" accept="image/*">
                                <div class="form-text">{{ __('Use a 1:1 (square) image. Used in tool cards and the detail-page badge area.') }}</div>
                                @error('squareImageUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if($squareImageUpload && method_exists($squareImageUpload, 'temporaryUrl'))
                                    <small class="text-muted d-block mt-2">{{ __('Preview (new upload)') }}</small>
                                    <img src="{{ $squareImageUpload->temporaryUrl() }}" alt="tool square image preview" class="rounded mt-2" style="width:88px; height:88px; object-fit:cover;">
                                @endif
                                @if($squareImagePath)
                                    @php($squarePreviewUrl = $landingMedia->publicUrl($squareImagePath))
                                    <small class="text-muted d-block mt-2">{{ __('Current: :path', ['path' => $squareImagePath]) }}</small>
                                    <button type="button" class="btn btn-sm btn-soft-danger mt-2" wire:click="clearSquareImage">{{ __('Remove current image') }}</button>
                                    @if($squarePreviewUrl)
                                        <img src="{{ $squarePreviewUrl }}" alt="tool square image" class="rounded mt-2" style="width:88px; height:88px; object-fit:cover;">
                                    @endif
                                @endif
                                @if($removeSquareImage)
                                    <div class="text-warning small mt-2">{{ __('Square image will be removed after saving.') }}</div>
                                @endif
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Sort') }}</label>
                                <input type="number" min="0" class="form-control @error('sortOrder') is-invalid @enderror" wire:model.defer="sortOrder">
                                @error('sortOrder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Status') }}</label>
                                <select class="form-select @error('toolStatus') is-invalid @enderror" wire:model.defer="toolStatus">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                </select>
                                @error('toolStatus') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Demo Type') }}</label>
                                <select class="form-select @error('demoType') is-invalid @enderror" wire:model.defer="demoType">
                                    <option value="">{{ __('Auto by slug (recommended)') }}</option>
                                    @foreach($this->demoTypeOptions as $demoTypeValue => $demoTypeLabel)
                                        <option value="{{ $demoTypeValue }}">{{ $demoTypeLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('Select which live demo layout to render at the end of the tool detail page.') }}</div>
                                @error('demoType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Demo Content Builder') }}</label>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-soft-primary" wire:click="openDemoConfigModal">
                                        {{ __('Open Demo Builder') }}
                                    </button>
                                    <span class="badge bg-secondary-subtle text-secondary align-self-center">{{ $demoType !== '' ? $demoType : __('Auto mode') }}</span>
                                </div>
                                <div class="form-text">{{ __('Use the standalone builder to upload audio/images and manage links without editing raw JSON.') }}</div>
                                @error('demoType') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Advanced: Demo Config JSON') }}</label>
                                <textarea
                                    class="form-control @error('demoConfigJson') is-invalid @enderror"
                                    rows="4"
                                    wire:model.defer="demoConfigJson"
                                    placeholder='{"type":"tts","version":1,"meta":{"sample_text":"..."},"items":[{"label":"Apollo Female","engine":"apollo","voice_id":"apollo_female_1","audio":"https://..."}]}'
                                    style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;"
                                ></textarea>
                                <div class="form-text">{{ __('Optional advanced override. Builder updates this automatically.') }}</div>
                                @error('demoConfigJson') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">{{ __('Tool Detail Right-Side Visual') }}</label>
                                <input type="file" class="form-control @error('heroImageUpload') is-invalid @enderror" wire:model="heroImageUpload" accept="image/*">
                                <div class="form-text">{{ __('Shown in the right visual panel on /tools/{slug}.') }}</div>
                                @error('heroImageUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if($heroImagePath)
                                    @php($heroPreviewUrl = $landingMedia->publicUrl($heroImagePath))
                                    <small class="text-muted d-block mt-2">{{ __('Current: :path', ['path' => $heroImagePath]) }}</small>
                                    <button type="button" class="btn btn-sm btn-soft-danger mt-2" wire:click="clearHeroImage">{{ __('Remove current visual') }}</button>
                                    @if($heroPreviewUrl)
                                        <img src="{{ $heroPreviewUrl }}" alt="tool detail visual" class="img-fluid rounded mt-2" style="max-height:120px;">
                                    @endif
                                @endif
                                @if($removeHeroImage)
                                    <div class="text-warning small mt-2">{{ __('Right-side visual will be removed after saving.') }}</div>
                                @endif
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">{{ __('Tools Listing Card Thumbnail') }}</label>
                                <input type="file" class="form-control @error('cardImageUpload') is-invalid @enderror" wire:model="cardImageUpload" accept="image/*">
                                <div class="form-text">{{ __('Shown on /tools card when provided. If empty, the square badge image (or fallback letter) is used.') }}</div>
                                @error('cardImageUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if($cardImagePath)
                                    @php($cardPreviewUrl = $landingMedia->publicUrl($cardImagePath))
                                    <small class="text-muted d-block mt-2">{{ __('Current: :path', ['path' => $cardImagePath]) }}</small>
                                    <button type="button" class="btn btn-sm btn-soft-danger mt-2" wire:click="clearCardImage">{{ __('Remove current thumbnail') }}</button>
                                    @if($cardPreviewUrl)
                                        <img src="{{ $cardPreviewUrl }}" alt="tool card image" class="img-fluid rounded mt-2" style="max-height:120px;">
                                    @endif
                                @endif
                                @if($removeCardImage)
                                    <div class="text-warning small mt-2">{{ __('Card thumbnail will be removed after saving.') }}</div>
                                @endif
                            </div>
                        </div>

                        <hr>

                        <ul class="nav nav-tabs mb-3">
                            @foreach(['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'] as $localeCode => $localeLabel)
                                <li class="nav-item">
                                    <a class="nav-link {{ $localeCode === 'en' ? 'active' : '' }}" data-bs-toggle="tab" href="#landingToolLocale-{{ $localeCode }}">{{ __($localeLabel) }}</a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="tab-content">
                            @foreach(['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'] as $localeCode => $localeLabel)
                                <div class="tab-pane fade {{ $localeCode === 'en' ? 'show active' : '' }}" id="landingToolLocale-{{ $localeCode }}">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">{{ __('Small Badge Above Title') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('badge.'.$localeCode) is-invalid @enderror" wire:model.defer="badge.{{ $localeCode }}">
                                            <div class="form-text">{{ __('Shown above the page title in the hero badge.') }}</div>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">{{ __('Page Title') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('title.'.$localeCode) is-invalid @enderror" wire:model.defer="title.{{ $localeCode }}">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">{{ __('Hero Lead Paragraph') }} ({{ $localeCode }})</label>
                                            <textarea class="form-control @error('heroText.'.$localeCode) is-invalid @enderror" rows="2" wire:model.defer="heroText.{{ $localeCode }}"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">{{ __('Tools List Card Description') }} ({{ $localeCode }})</label>
                                            <textarea class="form-control @error('summary.'.$localeCode) is-invalid @enderror" rows="2" wire:model.defer="summary.{{ $localeCode }}"></textarea>
                                            <div class="form-text">{{ __('Shown on the /tools listing card under the tool name.') }}</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('About Section Title') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('aboutTitle.'.$localeCode) is-invalid @enderror" wire:model.defer="aboutTitle.{{ $localeCode }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Use Cases Section Label') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('useCasesTitle.'.$localeCode) is-invalid @enderror" wire:model.defer="useCasesTitle.{{ $localeCode }}">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">{{ __('About Section Body') }} ({{ $localeCode }})</label>
                                            <textarea class="form-control @error('aboutCopy.'.$localeCode) is-invalid @enderror" rows="3" wire:model.defer="aboutCopy.{{ $localeCode }}"></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Use Cases (one per line)') }} ({{ $localeCode }})</label>
                                            <textarea class="form-control @error('useCasesText.'.$localeCode) is-invalid @enderror" rows="4" wire:model.defer="useCasesText.{{ $localeCode }}"></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Tools List Card Tags (one per line)') }} ({{ $localeCode }})</label>
                                            <textarea class="form-control @error('toolCardTagsText.'.$localeCode) is-invalid @enderror" rows="4" wire:model.defer="toolCardTagsText.{{ $localeCode }}"></textarea>
                                            <div class="form-text">{{ __('Shown as small pills on each tool card in /tools.') }}</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Meta Title') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('metaTitle.'.$localeCode) is-invalid @enderror" wire:model.defer="metaTitle.{{ $localeCode }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Meta Description') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('metaDescription.'.$localeCode) is-invalid @enderror" wire:model.defer="metaDescription.{{ $localeCode }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('App Download Section Title') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('appDownloadTitle.'.$localeCode) is-invalid @enderror" wire:model.defer="appDownloadTitle.{{ $localeCode }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('App Download Section Description') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('appDownloadBody.'.$localeCode) is-invalid @enderror" wire:model.defer="appDownloadBody.{{ $localeCode }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('iOS Button Label') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('appDownloadIosLabel.'.$localeCode) is-invalid @enderror" wire:model.defer="appDownloadIosLabel.{{ $localeCode }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Android Button Label') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('appDownloadAndroidLabel.'.$localeCode) is-invalid @enderror" wire:model.defer="appDownloadAndroidLabel.{{ $localeCode }}">
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <hr>

                        <div class="row g-3 mb-2">
                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="appDownloadEnabled" wire:model.defer="appDownloadEnabled">
                                    <label class="form-check-label" for="appDownloadEnabled">{{ __('Enable Apps Download Section') }}</label>
                                </div>
                                <div class="form-text">{{ __('Shown near the end of the tool detail page.') }}</div>
                                @error('appDownloadEnabled') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="appDownloadIosEnabled" wire:model.defer="appDownloadIosEnabled">
                                    <label class="form-check-label" for="appDownloadIosEnabled">{{ __('Show iOS Button') }}</label>
                                </div>
                                @error('appDownloadIosEnabled') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="appDownloadAndroidEnabled" wire:model.defer="appDownloadAndroidEnabled">
                                    <label class="form-check-label" for="appDownloadAndroidEnabled">{{ __('Show Android Button') }}</label>
                                </div>
                                @error('appDownloadAndroidEnabled') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('iOS Download URL') }}</label>
                                <input type="url" class="form-control @error('appDownloadIosUrl') is-invalid @enderror" wire:model.defer="appDownloadIosUrl" placeholder="https://apps.apple.com/app/id000000">
                                <div class="form-text">{{ __('Used only when iOS button is active.') }}</div>
                                @error('appDownloadIosUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Android Download URL') }}</label>
                                <input type="url" class="form-control @error('appDownloadAndroidUrl') is-invalid @enderror" wire:model.defer="appDownloadAndroidUrl" placeholder="https://play.google.com/store/apps/details?id=com.example.app">
                                <div class="form-text">{{ __('Used only when Android button is active.') }}</div>
                                @error('appDownloadAndroidUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="mb-1">{{ __('Feature Cards') }}</h6>
                                <p class="text-muted mb-0">{{ __('Structured items shown in the feature grid on tool detail page.') }}</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" wire:click="addFeatureItem">{{ __('Add Feature') }}</button>
                        </div>

                        @error('featureItems')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror

                        @foreach($featureItems as $index => $feature)
                            <div class="border rounded-3 p-3 mb-3" wire:key="landing-tool-feature-{{ $index }}">
                                <div class="row g-3 mb-2">
                                    <div class="col-md-4">
                                        <label class="form-label">{{ __('Icon') }}</label>
                                        <select class="form-select @error('featureItems.'.$index.'.icon') is-invalid @enderror" wire:model.defer="featureItems.{{ $index }}.icon">
                                            @foreach($this->featureIconOptions as $iconValue => $iconLabel)
                                                <option value="{{ $iconValue }}">{{ $iconLabel }} ({{ $iconValue }})</option>
                                            @endforeach
                                        </select>
                                        @error('featureItems.'.$index.'.icon') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">{{ __('Sort') }}</label>
                                        <input type="number" min="0" class="form-control @error('featureItems.'.$index.'.sort_order') is-invalid @enderror" wire:model.defer="featureItems.{{ $index }}.sort_order">
                                        @error('featureItems.'.$index.'.sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-secondary" wire:click="moveFeatureItemUp({{ $index }})">{{ __('Up') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-secondary" wire:click="moveFeatureItemDown({{ $index }})">{{ __('Down') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeFeatureItem({{ $index }})">{{ __('Remove') }}</button>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    @foreach(['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'] as $localeCode => $localeLabel)
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Title') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('featureItems.'.$index.'.title.'.$localeCode) is-invalid @enderror" wire:model.defer="featureItems.{{ $index }}.title.{{ $localeCode }}">
                                            @error('featureItems.'.$index.'.title.'.$localeCode) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">{{ __('Body') }} ({{ $localeCode }})</label>
                                            <input type="text" class="form-control @error('featureItems.'.$index.'.body.'.$localeCode) is-invalid @enderror" wire:model.defer="featureItems.{{ $index }}.body.{{ $localeCode }}">
                                            @error('featureItems.'.$index.'.body.'.$localeCode) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetToolForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingToolPageId ? __('Update Tool Page') : __('Create Tool Page') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="landingToolDemoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Demo Content Builder') }} @if($demoType !== '')<span class="text-muted">- {{ $demoType }}</span>@endif</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="closeDemoConfigModal"></button>
                </div>
                <div class="modal-body" style="max-height: calc(100vh - 220px); overflow-y: auto;">
                    @if($demoType === '')
                        <div class="alert alert-warning mb-0">{{ __('Please choose a Demo Type in the main modal first.') }}</div>
                    @else
                        <div class="alert alert-info">
                            <strong>{{ __('Pre-generated Demo Samples') }}</strong><br>
                            <span class="small">{{ __('Add examples here. Click "Done", then click "Update Tool Page" in the main modal to persist changes.') }}</span>
                        </div>

                        <div class="row g-3">
                            @if(in_array($demoType, ['tts', 'ctts'], true))
                                <div class="col-12">
                                    <label class="form-label">{{ __('Shared sample text') }}</label>
                                    <textarea class="form-control @error('demoMeta.sample_text') is-invalid @enderror" rows="4" wire:model.defer="demoMeta.sample_text"></textarea>
                                    @error('demoMeta.sample_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <div class="col-12 d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">{{ __('Examples') }}</h6>
                                @php($addExampleLabel = $demoType === 'ctts' ? __('Add clone example') : __('Add example'))
                                <button type="button" class="btn btn-sm btn-soft-primary" wire:click="addDemoItem">
                                    <i class="bi bi-plus-circle me-1"></i>{{ $addExampleLabel }}
                                </button>
                            </div>

                            @forelse($demoItems as $itemIndex => $item)
                                <div class="col-12" wire:key="landing-demo-item-{{ data_get($item, '__row_key', $itemIndex) }}">
                                    <div class="border rounded-3 p-3 bg-light-subtle">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <strong>{{ __('Example #:index', ['index' => $itemIndex + 1]) }}</strong>
                                            @if(count($demoItems) > 1)
                                                <button type="button" class="btn btn-sm btn-soft-danger" wire:click="removeDemoItem({{ $itemIndex }})">{{ __('Remove') }}</button>
                                            @endif
                                        </div>

                                        <div class="row g-3">
                                            @if($demoType === 'tts')
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Label / Name') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.label') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.label">
                                                    @error('demoItems.'.$itemIndex.'.label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Engine') }}</label>
                                                    <select class="form-select @error('demoItems.'.$itemIndex.'.engine') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.engine">
                                                        <option value="">{{ __('Select engine') }}</option>
                                                        <option value="apollo">Apollo</option>
                                                        <option value="delta">Delta</option>
                                                        <option value="xtts">XTTS</option>
                                                        <option value="ftts">FTTS</option>
                                                    </select>
                                                    @error('demoItems.'.$itemIndex.'.engine') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Voice ID (optional)') }}</label>
                                                    <select class="form-select @error('demoItems.'.$itemIndex.'.voice_id') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.voice_id">
                                                        <option value="">{{ __('Select voice from DB') }}</option>
                                                        @foreach($this->availableDemoVoices as $voiceCode => $voiceLabel)
                                                            <option value="{{ $voiceCode }}">{{ $voiceLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('demoItems.'.$itemIndex.'.voice_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Audio URL or storage path') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.audio_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.audio_url" placeholder="landing/demos/.../sample.mp3">
                                                    @error('demoItems.'.$itemIndex.'.audio_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Upload audio') }}</label>
                                                    <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.audio_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.audio_upload" accept="audio/*">
                                                    @error('demoItems.'.$itemIndex.'.audio_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Description (optional)') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.description') is-invalid @enderror" rows="2" wire:model.defer="demoItems.{{ $itemIndex }}.description"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                            @elseif($demoType === 'ctts')
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Title / Label') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.title') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.title">
                                                    @error('demoItems.'.$itemIndex.'.title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Source speaker label') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.source_label') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.source_label">
                                                    @error('demoItems.'.$itemIndex.'.source_label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Source audio URL/path') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.source_audio_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.source_audio_url">
                                                    @error('demoItems.'.$itemIndex.'.source_audio_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Upload source audio') }}</label>
                                                    <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.source_audio_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.source_audio_upload" accept="audio/*">
                                                    @error('demoItems.'.$itemIndex.'.source_audio_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Cloned speaker label') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.cloned_label') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.cloned_label">
                                                    @error('demoItems.'.$itemIndex.'.cloned_label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Cloned audio URL/path') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.cloned_audio_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.cloned_audio_url">
                                                    @error('demoItems.'.$itemIndex.'.cloned_audio_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Upload cloned audio') }}</label>
                                                    <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.cloned_audio_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.cloned_audio_upload" accept="audio/*">
                                                    @error('demoItems.'.$itemIndex.'.cloned_audio_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Notes (optional)') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.notes') is-invalid @enderror" rows="2" wire:model.defer="demoItems.{{ $itemIndex }}.notes"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                            @elseif($demoType === 'asr')
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Title / Label') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.title') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.title">
                                                    @error('demoItems.'.$itemIndex.'.title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Audio URL/path') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.audio_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.audio_url">
                                                    @error('demoItems.'.$itemIndex.'.audio_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Upload audio') }}</label>
                                                    <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.audio_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.audio_upload" accept="audio/*">
                                                    @error('demoItems.'.$itemIndex.'.audio_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">{{ __('Language') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.language') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.language" placeholder="ku">
                                                    @error('demoItems.'.$itemIndex.'.language') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">{{ __('Confidence') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.confidence') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.confidence" placeholder="97%">
                                                    @error('demoItems.'.$itemIndex.'.confidence') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">{{ __('Transcript') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.transcript') is-invalid @enderror" rows="4" wire:model.defer="demoItems.{{ $itemIndex }}.transcript"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.transcript') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">{{ __('Notes (optional)') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.notes') is-invalid @enderror" rows="2" wire:model.defer="demoItems.{{ $itemIndex }}.notes"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                            @elseif($demoType === 'stem')
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Title / Label') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.title') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.title">
                                                    @error('demoItems.'.$itemIndex.'.title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Original mix URL/path') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.original_audio_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.original_audio_url">
                                                    @error('demoItems.'.$itemIndex.'.original_audio_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Upload original mix') }}</label>
                                                    <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.original_audio_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.original_audio_upload" accept="audio/*">
                                                    @error('demoItems.'.$itemIndex.'.original_audio_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                @foreach(['vocals' => 'Vocals', 'drums' => 'Drums', 'bass' => 'Bass', 'other' => 'Other'] as $stemKey => $stemLabel)
                                                    <div class="col-md-6">
                                                        <label class="form-label">{{ __($stemLabel) }} {{ __('URL/path') }}</label>
                                                        <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.'.$stemKey.'_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.{{ $stemKey }}_url">
                                                        @error('demoItems.'.$itemIndex.'.'.$stemKey.'_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">{{ __('Upload') }} {{ __($stemLabel) }}</label>
                                                        <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.'.$stemKey.'_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.{{ $stemKey }}_upload" accept="audio/*">
                                                        @error('demoItems.'.$itemIndex.'.'.$stemKey.'_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                    </div>
                                                @endforeach
                                                <div class="col-12">
                                                    <label class="form-label">{{ __('Notes (optional)') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.notes') is-invalid @enderror" rows="2" wire:model.defer="demoItems.{{ $itemIndex }}.notes"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                            @elseif($demoType === 'ocr')
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Title (optional)') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.title') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.title">
                                                    @error('demoItems.'.$itemIndex.'.title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Image/GIF URL/path') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.image_url') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.image_url">
                                                    @error('demoItems.'.$itemIndex.'.image_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Upload image/GIF') }}</label>
                                                    <input type="file" class="form-control @error('demoItems.'.$itemIndex.'.image_upload') is-invalid @enderror" wire:model="demoItems.{{ $itemIndex }}.image_upload" accept="image/*">
                                                    @error('demoItems.'.$itemIndex.'.image_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    @php($ocrUpload = data_get($demoItems, $itemIndex.'.image_upload'))
                                                    @if($ocrUpload && method_exists($ocrUpload, 'temporaryUrl'))
                                                        <label class="form-label">{{ __('Preview') }}</label>
                                                        <img src="{{ $ocrUpload->temporaryUrl() }}" alt="ocr preview" class="img-fluid rounded border">
                                                    @endif
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">{{ __('Extracted text') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.extracted_text') is-invalid @enderror" rows="5" wire:model.defer="demoItems.{{ $itemIndex }}.extracted_text"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.extracted_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">{{ __('Notes (optional)') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.notes') is-invalid @enderror" rows="2" wire:model.defer="demoItems.{{ $itemIndex }}.notes"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                            @elseif($demoType === 'translation')
                                                <div class="col-md-4">
                                                    <label class="form-label">{{ __('Title (optional)') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.title') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.title">
                                                    @error('demoItems.'.$itemIndex.'.title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">{{ __('Source language') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.source_lang') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.source_lang" placeholder="ku">
                                                    @error('demoItems.'.$itemIndex.'.source_lang') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">{{ __('Target language') }}</label>
                                                    <input type="text" class="form-control @error('demoItems.'.$itemIndex.'.target_lang') is-invalid @enderror" wire:model.defer="demoItems.{{ $itemIndex }}.target_lang" placeholder="en">
                                                    @error('demoItems.'.$itemIndex.'.target_lang') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Source text') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.source_text') is-invalid @enderror" rows="4" wire:model.defer="demoItems.{{ $itemIndex }}.source_text"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.source_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">{{ __('Target text') }}</label>
                                                    <textarea class="form-control @error('demoItems.'.$itemIndex.'.target_text') is-invalid @enderror" rows="4" wire:model.defer="demoItems.{{ $itemIndex }}.target_text"></textarea>
                                                    @error('demoItems.'.$itemIndex.'.target_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-12">
                                    <div class="alert alert-light border mb-0">{{ __('No examples configured yet. Click "Add example".') }}</div>
                                </div>
                            @endforelse
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <span class="text-muted small me-auto">{{ __('Changes are stored when you click "Update Tool Page" in the main modal.') }}</span>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" wire:click="closeDemoConfigModal">{{ __('Done') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="landingToolPageDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">{{ __('Delete Tool Page') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetDeleteState"></button></div>
                <div class="modal-body"><p class="mb-0">{{ __('Delete tool page ":slug"? This action cannot be undone.', ['slug' => $toolPageDeleteLabel]) }}</p></div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetDeleteState">{{ __('Cancel') }}</button><button type="button" class="btn btn-danger" wire:click="performToolPageDelete">{{ __('Delete') }}</button></div>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script data-navigate-once>
                (() => {
                    if (window.__LANDING_TOOLS_MODAL_EVENTS__) {
                        return;
                    }

                    window.__LANDING_TOOLS_MODAL_EVENTS__ = true;
                    const modalIds = ['landingToolPageModal', 'landingToolDemoModal', 'landingToolPageDeleteModal'];

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

                    window.addEventListener('landing-tools:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('landing-tools:modal-hide', (event) => {
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

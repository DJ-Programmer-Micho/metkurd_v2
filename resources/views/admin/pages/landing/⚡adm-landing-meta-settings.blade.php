<?php

use App\Support\Admin\ManagesLandingMetaSettingsPage;
use App\Support\Landing\SiteMetaSettingsRepository;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesLandingMetaSettingsPage;
    use WithFileUploads;
};
?>

<x-slot:title>{{ __('Landing Meta Settings') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $metaRepository = app(SiteMetaSettingsRepository::class);
    $assetWithVersion = static function (string $path): string {
        $trimmed = ltrim(trim($path), '/');
        $url = asset($trimmed);
        $fullPath = public_path($trimmed);

        if (! is_file($fullPath)) {
            return $url;
        }

        $version = @filemtime($fullPath);
        if (! is_int($version) || $version <= 0) {
            return $url;
        }

        return $url . '?v=' . $version;
    };
    $defaultFaviconUrl = $assetWithVersion('favicon.ico');
    $defaultFaviconSvgUrl = $assetWithVersion('favicon.svg');
    $defaultAppleTouchIconUrl = $assetWithVersion('apple-touch-icon.png');
    $effectiveFaviconUrl = $metaRepository->publicUrl($faviconPath) ?: $defaultFaviconUrl;
    $effectiveAppleTouchIconUrl = $metaRepository->publicUrl($appleTouchIconPath) ?: $defaultAppleTouchIconUrl;
@endphp

<div class="container-fluid">
    <form wire:submit.prevent="saveMetaSettings">
        <div class="row mb-3">
            <div class="col-12 d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">{{ __('Global Meta & Identity') }}</h4>
                    <p class="text-muted mb-0">{{ __('Manage favicon, app icons, OG/Twitter images, and site-level meta defaults.') }}</p>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="ri-save-line align-bottom me-1"></i>{{ __('Save Meta Settings') }}
                </button>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title mb-0">{{ __('Default SEO Text') }}</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Meta Title') }}</label>
                        <input type="text" class="form-control @error('defaultMetaTitle') is-invalid @enderror" wire:model.defer="defaultMetaTitle">
                        <div class="form-text">{{ __('Used when a page does not provide a specific title.') }}</div>
                        @error('defaultMetaTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Meta Description') }}</label>
                        <textarea class="form-control @error('defaultMetaDescription') is-invalid @enderror" rows="2" wire:model.defer="defaultMetaDescription"></textarea>
                        @error('defaultMetaDescription') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Open Graph Title') }}</label>
                        <input type="text" class="form-control @error('defaultOgTitle') is-invalid @enderror" wire:model.defer="defaultOgTitle">
                        <div class="form-text">{{ __('Shown when pages are shared on social platforms.') }}</div>
                        @error('defaultOgTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Open Graph Description') }}</label>
                        <textarea class="form-control @error('defaultOgDescription') is-invalid @enderror" rows="2" wire:model.defer="defaultOgDescription"></textarea>
                        @error('defaultOgDescription') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Twitter/X Title') }}</label>
                        <input type="text" class="form-control @error('defaultTwitterTitle') is-invalid @enderror" wire:model.defer="defaultTwitterTitle">
                        @error('defaultTwitterTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Twitter/X Description') }}</label>
                        <textarea class="form-control @error('defaultTwitterDescription') is-invalid @enderror" rows="2" wire:model.defer="defaultTwitterDescription"></textarea>
                        @error('defaultTwitterDescription') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">{{ __('Public Meta Assets') }}</h5></div>
            <div class="card-body">
                <div class="alert alert-info py-2 px-3">
                    <small class="mb-0 d-block">{{ __('These files are saved on the configured landing media disk to guarantee access across all app nodes and social/SEO crawlers.') }}</small>
                </div>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Favicon (browser tab)') }}</label>
                        <input type="file" class="form-control @error('faviconUpload') is-invalid @enderror" wire:model="faviconUpload" accept=".ico,image/*">
                        <div class="form-text">{{ __('Used for browser tab icon and bookmarks.') }}</div>
                        @error('faviconUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted d-block mt-2">{{ __('Effective URL: :url', ['url' => $effectiveFaviconUrl]) }}</small>
                        <small class="text-muted d-block">{{ __('SVG fallback: :url', ['url' => $defaultFaviconSvgUrl]) }}</small>
                        @if($faviconPath)
                            <small class="text-muted d-block mt-2">{{ $faviconPath }}</small>
                            @if($metaRepository->publicUrl($faviconPath))
                                <img src="{{ $metaRepository->publicUrl($faviconPath) }}" alt="favicon" class="img-fluid rounded mt-2" style="max-height:64px;">
                            @endif
                        @else
                            <img src="{{ $defaultFaviconUrl }}" alt="default favicon" class="img-fluid rounded mt-2" style="max-height:64px;">
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Apple Touch Icon') }}</label>
                        <input type="file" class="form-control @error('appleTouchIconUpload') is-invalid @enderror" wire:model="appleTouchIconUpload" accept="image/*">
                        <div class="form-text">{{ __('Used when users add your site to iPhone/iPad home screen.') }}</div>
                        @error('appleTouchIconUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <small class="text-muted d-block mt-2">{{ __('Effective URL: :url', ['url' => $effectiveAppleTouchIconUrl]) }}</small>
                        @if($appleTouchIconPath && $metaRepository->publicUrl($appleTouchIconPath))
                            <small class="text-muted d-block mt-2">{{ $appleTouchIconPath }}</small>
                            <img src="{{ $metaRepository->publicUrl($appleTouchIconPath) }}" alt="apple icon" class="img-fluid rounded mt-2" style="max-height:72px;">
                        @else
                            <img src="{{ $defaultAppleTouchIconUrl }}" alt="default apple icon" class="img-fluid rounded mt-2" style="max-height:72px;">
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('App Icon 192x192') }}</label>
                        <input type="file" class="form-control @error('appIcon192Upload') is-invalid @enderror" wire:model="appIcon192Upload" accept="image/*">
                        <div class="form-text">{{ __('Used by Android/PWA launchers (192x192).') }}</div>
                        @error('appIcon192Upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if($appIcon192Path && $metaRepository->publicUrl($appIcon192Path))
                            <small class="text-muted d-block mt-2">{{ $appIcon192Path }}</small>
                            <img src="{{ $metaRepository->publicUrl($appIcon192Path) }}" alt="app icon 192" class="img-fluid rounded mt-2" style="max-height:72px;">
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('App Icon 512x512') }}</label>
                        <input type="file" class="form-control @error('appIcon512Upload') is-invalid @enderror" wire:model="appIcon512Upload" accept="image/*">
                        <div class="form-text">{{ __('Used by Android/PWA launchers (512x512).') }}</div>
                        @error('appIcon512Upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if($appIcon512Path && $metaRepository->publicUrl($appIcon512Path))
                            <small class="text-muted d-block mt-2">{{ $appIcon512Path }}</small>
                            <img src="{{ $metaRepository->publicUrl($appIcon512Path) }}" alt="app icon 512" class="img-fluid rounded mt-2" style="max-height:72px;">
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Open Graph Image') }}</label>
                        <input type="file" class="form-control @error('ogImageUpload') is-invalid @enderror" wire:model="ogImageUpload" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp">
                        <div class="form-text">{{ __('Shown when pages are shared in chat apps and social feeds. Use PNG/JPG/WEBP, around 1200x630, and avoid favicon/app-icon style images.') }}</div>
                        @error('ogImageUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if($ogImagePath && $metaRepository->publicUrl($ogImagePath))
                            <small class="text-muted d-block mt-2">{{ $ogImagePath }}</small>
                            <img src="{{ $metaRepository->publicUrl($ogImagePath) }}" alt="og image" class="img-fluid rounded mt-2" style="max-height:120px;">
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Default Twitter/X Image') }}</label>
                        <input type="file" class="form-control @error('twitterImageUpload') is-invalid @enderror" wire:model="twitterImageUpload" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp">
                        <div class="form-text">{{ __('Used by Twitter/X cards when no page-specific image is set. Use PNG/JPG/WEBP, around 1200x630, and avoid icon-like uploads.') }}</div>
                        @error('twitterImageUpload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if($twitterImagePath && $metaRepository->publicUrl($twitterImagePath))
                            <small class="text-muted d-block mt-2">{{ $twitterImagePath }}</small>
                            <img src="{{ $metaRepository->publicUrl($twitterImagePath) }}" alt="twitter image" class="img-fluid rounded mt-2" style="max-height:120px;">
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

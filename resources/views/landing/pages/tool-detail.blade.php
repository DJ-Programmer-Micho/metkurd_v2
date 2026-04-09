<?php

use App\Support\LandingContent;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('landing::layouts.app')] class extends Component
{
    public string $toolCode = '';

    public function mount(string $slug): void
    {
        $normalized = Str::of($slug)->lower()->replace('_', '-')->toString();

        $toolCode = match ($normalized) {
            'tts' => 'tts',
            'ctts', 'clone-tts', 'clone-xtts' => 'clone_tts',
            'asr', 'wasr' => 'asr',
            'ocr' => 'ocr',
            'stem' => 'stem',
            default => '',
        };

        abort_if($toolCode === '', 404);
        abort_if(LandingContent::section("tool_catalog.{$toolCode}") === [], 404);

        $this->toolCode = $toolCode;
    }
};
?>

@php
    $toolPage = LandingContent::section("tool_pages.{$toolCode}");
    $toolCatalog = LandingContent::section("tool_catalog.{$toolCode}");
    $canonicalSlug = data_get($toolCatalog, 'slug', $toolCode);
    $title = data_get($toolCatalog, 'title', __('Tool'));
@endphp

<x-slot:title>{{ data_get($toolPage, 'meta_title', $title) }}</x-slot:title>
<x-slot:description>{{ data_get($toolPage, 'meta_description', LandingContent::text('site.meta_description')) }}</x-slot:description>
<x-slot:canonical>{{ route('landing.tools.show', ['locale' => app()->getLocale(), 'slug' => $canonicalSlug]) }}</x-slot:canonical>

<div>
    <section class="hero py-5 mt-5">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6 reveal">
                    <span class="hero-badge mb-3">
                        <i class="{{ data_get($toolCatalog, 'icon', 'bi bi-grid-1x2') }}"></i>
                        {{ data_get($toolPage, 'badge', $title) }}
                    </span>
                    <h1 class="display-hero mb-3">{{ data_get($toolPage, 'title') }}</h1>
                    <p class="lead-soft mb-4">{{ data_get($toolPage, 'lead') }}</p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="{{ route('app.signup') }}" class="btn btn-glow rounded-pill px-4" wire:navigate>{{ LandingContent::text('common.get_started') }}</a>
                        <a href="{{ route('landing.pricing', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-soft rounded-pill px-4" wire:navigate>{{ LandingContent::text('common.view_pricing') }}</a>
                    </div>
                </div>

                <div class="col-lg-6 reveal">
                    <div class="screenshot-frame grid-shine">
                        <div class="screenshot-inner d-flex flex-column gap-3">
                            <div class="glass-card p-3 d-flex justify-content-between">
                                <span>{{ LandingContent::text('tool_detail.model_label') }}</span>
                                <span class="kbd-soft">{{ $title }}</span>
                            </div>
                            <div class="glass-card p-3 d-flex justify-content-between">
                                <span>{{ LandingContent::text('tool_detail.status_label') }}</span>
                                <span class="badge-soft">{{ LandingContent::text('tool_detail.status_ready') }}</span>
                            </div>
                            <div class="glass-card p-3 flex-grow-1">
                                <div class="code-lines">
                                    <div style="width:92%"></div>
                                    <div style="width:76%"></div>
                                    <div style="width:88%"></div>
                                    <div style="width:69%"></div>
                                    <div style="width:58%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="policy-card glass-card reveal">
                        <span class="section-badge mb-3">
                            <i class="bi bi-lightbulb"></i>
                            {{ LandingContent::text('common.learn_more') }}
                        </span>
                        <h2 class="section-title h1 mb-3">{{ data_get($toolPage, 'about_title') }}</h2>
                        <p class="text-muted-soft">{{ data_get($toolPage, 'about_copy') }}</p>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="policy-card glass-card reveal">
                        <span class="section-badge mb-3">
                            <i class="bi bi-briefcase"></i>
                            {{ data_get($toolPage, 'use_cases_title') }}
                        </span>
                        <ul class="check-list">
                            @foreach((array) data_get($toolPage, 'use_cases', []) as $useCase)
                                <li>
                                    <i class="bi bi-check-circle-fill"></i>
                                    <span>{{ $useCase }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <livewire:landing::components.feature-grid :items="(array) data_get($toolPage, 'features', [])" columns="col-md-4" />
        </div>
    </section>
</div>

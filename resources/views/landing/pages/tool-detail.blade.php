<?php

use App\Support\Landing\LandingToolPageCatalog;
use App\Support\LandingContent;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('landing::layouts.app')] class extends Component
{
    /** @var array<string, mixed> */
    public array $tool = [];

    public function mount(string $slug): void
    {
        $tool = app(LandingToolPageCatalog::class)->findForLocaleBySlug($slug, app()->getLocale());

        abort_if(! is_array($tool) || $tool === [], 404);

        $this->tool = $tool;
    }
};
?>

@php
    $tool = $this->tool;
    $canonicalSlug = (string) data_get($tool, 'slug', request()->route('slug'));
    $title = (string) data_get($tool, 'title', LandingContent::text('tool_detail.fallback_title'));
    $description = (string) data_get($tool, 'meta_description', LandingContent::text('site.meta_description'));
    $toolSquareImage = (string) data_get($tool, 'square_image_url', '');
    $featureCards = (array) data_get($tool, 'feature_cards', []);
    $locale = app()->getLocale();
    $toolUrl = route('landing.tools.show', ['locale' => $locale, 'slug' => $canonicalSlug]);

    if ($featureCards === []) {
        $featureCards = collect((array) data_get($tool, 'feature_bullets', []))
            ->map(fn ($bullet) => trim((string) $bullet))
            ->filter()
            ->values()
            ->map(fn (string $bullet) => [
                'icon' => 'bi bi-stars',
                'title' => $bullet,
                'copy' => '',
            ])
            ->all();
    }

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => LandingContent::text('nav.home'),
                'item' => route('landing.home', ['locale' => $locale]),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => LandingContent::text('nav.tools'),
                'item' => route('landing.tools', ['locale' => $locale]),
            ],
            [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $title,
                'item' => $toolUrl,
            ],
        ],
    ];

    $softwareSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => $title,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'inLanguage' => $locale,
        'description' => $description,
        'url' => $toolUrl,
        'brand' => [
            '@type' => 'Brand',
            'name' => LandingContent::text('site.name'),
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'IQD',
            'availability' => 'https://schema.org/InStock',
            'url' => route('landing.pricing', ['locale' => $locale]),
        ],
    ];
@endphp

<x-slot:title>{{ data_get($tool, 'meta_title', $title) }}</x-slot:title>
<x-slot:description>{{ $description }}</x-slot:description>
<x-slot:canonical>{{ route('landing.tools.show', ['locale' => app()->getLocale(), 'slug' => $canonicalSlug]) }}</x-slot:canonical>

@push('meta')
    <script type="application/ld+json">
        @json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    </script>
    <script type="application/ld+json">
        @json($softwareSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    </script>
@endpush

<div>
    <section class="hero py-5 mt-5">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6 reveal">
                    <span class="hero-badge mb-3">
                        @if($toolSquareImage !== '')
                            <img src="{{ $toolSquareImage }}" alt="{{ $title }}" class="tool-badge-image" loading="lazy">
                        @else
                            <span class="tool-fallback-letter">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($title, 0, 1)) }}</span>
                        @endif
                        {{ data_get($tool, 'badge', $title) }}
                    </span>
                    <h1 class="display-hero mb-3">{{ $title }}</h1>
                    <p class="lead-soft mb-4">{{ data_get($tool, 'hero_text') }}</p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="{{ route('app.signup') }}" class="btn btn-glow rounded-pill px-4" wire:navigate>{{ LandingContent::text('common.get_started') }}</a>
                        <a href="{{ route('landing.pricing', ['locale' => app()->getLocale()]) }}" class="btn btn-outline-soft rounded-pill px-4" wire:navigate>{{ LandingContent::text('common.view_pricing') }}</a>
                    </div>
                </div>

                <div class="col-lg-6 reveal">
                    <div class="screenshot-frame grid-shine">
                        <div class="screenshot-inner d-flex flex-column gap-3">
                            @if(data_get($tool, 'hero_image_url'))
                                <img
                                    src="{{ data_get($tool, 'hero_image_url') }}"
                                    alt="{{ $title }}"
                                    class="img-fluid rounded-4 w-100"
                                    loading="lazy"
                                >
                            @else
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
                            @endif
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
                        <h2 class="section-title h1 mb-3">{{ data_get($tool, 'about_title') }}</h2>
                        <p class="text-muted-soft">{{ data_get($tool, 'about_copy') }}</p>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="policy-card glass-card reveal">
                        <span class="section-badge mb-3">
                            <i class="bi bi-briefcase"></i>
                            {{ data_get($tool, 'use_cases_title', LandingContent::text('tool_detail.use_cases_title')) }}
                        </span>
                        <ul class="check-list">
                            @foreach((array) data_get($tool, 'use_cases', []) as $useCase)
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
            <livewire:landing::components.feature-grid :items="$featureCards" columns="col-md-4" />
        </div>
    </section>

    <livewire:landing::components.tool-demo :tool="$tool" />

    <livewire:landing::components.tool-app-download :tool="$tool" />
</div>

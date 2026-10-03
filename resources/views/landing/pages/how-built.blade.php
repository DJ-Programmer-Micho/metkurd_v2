<?php

use App\Support\LandingContent;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('landing::layouts.app')] class extends Component
{
};
?>

@php
    $locale = app()->getLocale();
    $timeline = LandingContent::section('how_built_page.timeline');

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => LandingContent::text('nav.home'),
                'item' => \App\Support\Landing\PublicSiteUrl::route('landing.home', ['locale' => $locale]),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => LandingContent::text('how_built_page.title'),
                'item' => \App\Support\Landing\PublicSiteUrl::route('landing.how-built', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('how_built_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('how_built_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('how_built_page.meta.keywords') }}</x-slot:keywords>

@push('meta')
    <script type="application/ld+json">
        @json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT)
    </script>
@endpush

<div>
    <section class="hero py-5 mt-5">
        <div class="container">
            <div class="text-center reveal">
                <span class="hero-badge mb-3">
                    <i class="bi bi-diagram-3"></i>
                    {{ LandingContent::text('how_built_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('how_built_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('how_built_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="d-grid gap-4">
                @foreach((array) $timeline as $index => $step)
                    <div class="step-item glass-card p-4 reveal">
                        <div class="step-number">{{ $index + 1 }}</div>
                        <div>
                            <h2 class="h4 mb-2">{{ $step['title'] }}</h2>
                            <p class="text-muted-soft mb-0">{{ $step['copy'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>

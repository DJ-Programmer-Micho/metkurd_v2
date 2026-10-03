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
    $sections = LandingContent::section('kurdish_ai_challenges_page.sections');

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
                'name' => LandingContent::text('kurdish_ai_challenges_page.title'),
                'item' => \App\Support\Landing\PublicSiteUrl::route('landing.kurdish-ai-challenges', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('kurdish_ai_challenges_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('kurdish_ai_challenges_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('kurdish_ai_challenges_page.meta.keywords') }}</x-slot:keywords>

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
                    <i class="bi bi-exclamation-circle"></i>
                    {{ LandingContent::text('kurdish_ai_challenges_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('kurdish_ai_challenges_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('kurdish_ai_challenges_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="row g-4">
                @foreach((array) $sections as $section)
                    <div class="col-md-6">
                        <div class="policy-card glass-card reveal h-100">
                            <h2 class="h4 mb-3">{{ $section['title'] }}</h2>
                            <p class="text-muted-soft mb-0">{{ $section['copy'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>

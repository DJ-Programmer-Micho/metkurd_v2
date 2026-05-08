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
    $facts = LandingContent::section('overview_page.facts');
    $tools = LandingContent::section('overview_page.tools');
    $limitations = LandingContent::section('overview_page.limitations');

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
                'name' => LandingContent::text('overview_page.title'),
                'item' => route('landing.overview', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('overview_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('overview_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('overview_page.meta.keywords') }}</x-slot:keywords>

@push('meta')
    <script type="application/ld+json">
        @json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    </script>
@endpush

<div>
    <section class="hero py-5 mt-5">
        <div class="container">
            <div class="text-center reveal">
                <span class="hero-badge mb-3">
                    <i class="bi bi-info-circle"></i>
                    {{ LandingContent::text('overview_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('overview_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('overview_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="policy-card glass-card h-100 reveal">
                        <h2 class="h4 mb-3">{{ LandingContent::text('overview_page.one_sentence_heading') }}</h2>
                        <p class="text-muted-soft mb-0">{{ LandingContent::text('overview_page.one_sentence') }}</p>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="policy-card glass-card h-100 reveal">
                        <h2 class="h4 mb-3">{{ LandingContent::text('overview_page.one_paragraph_heading') }}</h2>
                        <p class="text-muted-soft mb-0">{{ LandingContent::text('overview_page.one_paragraph') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="policy-card glass-card reveal h-100">
                        <h2 class="h4 mb-3">{{ LandingContent::text('overview_page.facts_heading') }}</h2>
                        <ul class="check-list mb-0">
                            @foreach((array) $facts as $fact)
                                <li>
                                    <i class="bi bi-check-circle-fill"></i>
                                    <span>{{ $fact }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="policy-card glass-card reveal h-100">
                        <h2 class="h4 mb-3">{{ LandingContent::text('overview_page.tools_heading') }}</h2>
                        <ul class="check-list mb-4">
                            @foreach((array) $tools as $tool)
                                <li>
                                    <i class="bi bi-tools"></i>
                                    <span>{{ $tool }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <h3 class="h5 mb-3">{{ LandingContent::text('overview_page.limitations_heading') }}</h3>
                        <ul class="check-list mb-0">
                            @foreach((array) $limitations as $limitation)
                                <li>
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span>{{ $limitation }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

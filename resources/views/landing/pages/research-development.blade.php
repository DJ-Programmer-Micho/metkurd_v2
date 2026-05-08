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
    $sections = LandingContent::section('research_development_page.sections');

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
                'name' => LandingContent::text('research_development_page.title'),
                'item' => route('landing.research-development', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('research_development_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('research_development_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('research_development_page.meta.keywords') }}</x-slot:keywords>

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
                    <i class="bi bi-journal-text"></i>
                    {{ LandingContent::text('research_development_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('research_development_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('research_development_page.lead') }}</p>
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

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
                'name' => LandingContent::text('tools_page.title'),
                'item' => route('landing.tools', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('tools_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('tools_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('tools_page.meta.keywords') }}</x-slot:keywords>

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
                    <i class="bi bi-grid"></i>
                    {{ LandingContent::text('tools_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('tools_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('tools_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <livewire:landing::components.tool-grid
                :key="'tools-grid-' . app()->getLocale()"
                button-style="button"
                :show-meta="false"
                :button-label="LandingContent::text('common.open_page')"
            />
        </div>
    </section>
</div>

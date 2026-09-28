<?php

use App\Support\LandingContent;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('landing::layouts.app')] class extends Component
{
};
?>

@php
    $cards = LandingContent::section('privacy_page.cards');
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
                'name' => LandingContent::text('privacy_page.title'),
                'item' => route('landing.privacy', ['locale' => $locale]),
            ],
        ],
    ];
@endphp

<x-slot:title>{{ LandingContent::text('privacy_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('privacy_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('privacy_page.meta.keywords') }}</x-slot:keywords>

@push('meta')
    <script type="application/ld+json">
        @json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT)
    </script>
@endpush

<div>
    <section class="hero py-5">
        <div class="container">
            <div class="text-center reveal">
                <span class="hero-badge mb-3">
                    <i class="bi bi-shield-lock"></i>
                    {{ LandingContent::text('privacy_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('privacy_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('privacy_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="row g-4">
                @foreach((array) $cards as $card)
                    <div class="col-md-6">
                        <div class="policy-card glass-card reveal">
                            <h3>{{ $card['title'] }}</h3>
                            <p class="text-muted-soft mb-0">{{ $card['copy'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>

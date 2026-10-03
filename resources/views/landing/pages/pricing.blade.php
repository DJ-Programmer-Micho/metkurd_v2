<?php

use App\Support\LandingContent;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('landing::layouts.app')] class extends Component
{
};
?>

@php
    $highlights = LandingContent::section('pricing_page.highlights');
    $faqHeading = LandingContent::section('pricing_page.faq_heading');
    $faqs = LandingContent::section('pricing_page.faqs');
    $locale = app()->getLocale();

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
                'name' => LandingContent::text('pricing_page.title'),
                'item' => \App\Support\Landing\PublicSiteUrl::route('landing.pricing', ['locale' => $locale]),
            ],
        ],
    ];

    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect((array) $faqs)
            ->map(function (array $faq) {
                return [
                    '@type' => 'Question',
                    'name' => (string) ($faq['title'] ?? ''),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => (string) ($faq['copy'] ?? ''),
                    ],
                ];
            })
            ->filter(fn (array $item) => trim((string) ($item['name'] ?? '')) !== '')
            ->values()
            ->all(),
    ];

@endphp

<x-slot:title>{{ LandingContent::text('pricing_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('pricing_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('pricing_page.meta.keywords') }}</x-slot:keywords>

@push('meta')
    <script type="application/ld+json">
        @json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT)
    </script>
    <script type="application/ld+json">
        @json($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT)
    </script>
@endpush

<div>
    <section class="hero py-5 mt-5">
        <div class="container">
            <div class="text-center reveal">
                <span class="hero-badge mb-3">
                    <i class="bi bi-credit-card-2-front"></i>
                    {{ LandingContent::text('pricing_page.badge') }}
                </span>
                <h1 class="display-hero mb-3">{{ LandingContent::text('pricing_page.title') }}</h1>
                <p class="lead-soft mx-auto">{{ LandingContent::text('pricing_page.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <livewire:landing::components.pricing-grid switch-style="toggle" />
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <livewire:landing::components.feature-grid :items="$highlights" columns="col-md-6 col-xl-4" />
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <span class="section-badge mb-3">
                    <i class="bi bi-question-circle"></i>
                    {{ $faqHeading['badge'] ?? LandingContent::text('pricing_page.faq_heading.badge') }}
                </span>
                <h2 class="section-title">{{ $faqHeading['title'] ?? LandingContent::text('pricing_page.faq_heading.title') }}</h2>
            </div>

            <div class="row g-4">
                @foreach((array) $faqs as $faq)
                    <div class="col-md-6">
                        <div class="faq-item glass-card reveal">
                            <h3 class="h5">{{ $faq['title'] }}</h3>
                            <p class="text-muted-soft mb-0">{{ $faq['copy'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>

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
    $hero = LandingContent::section('home.hero');
    $preview = LandingContent::section('home.preview');
    $stats = LandingContent::section('home.stats');
    $toolsHeading = LandingContent::section('home.tools');
    $demo = LandingContent::section('home.demo');
    $reasons = LandingContent::section('home.reasons');
    $workflow = LandingContent::section('home.workflow');
    $pricingHeading = LandingContent::section('home.pricing_heading');
    $testimonial = LandingContent::section('home.testimonial');
    $faqHeading = LandingContent::section('home.faq_heading');
    $faqs = LandingContent::section('home.faqs');
    $demoSampleText = LandingContent::text('home.demo.sample_text');
@endphp

<x-slot:title>{{ LandingContent::text('home.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('home.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('home.meta.keywords') }}</x-slot:keywords>

<div>
    <section class="hero">
        <div class="hero-orb orb-1"></div>
        <div class="hero-orb orb-2"></div>
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <span class="hero-badge mb-4 reveal">
                        <i class="bi bi-stars"></i>
                        {{ $hero['badge'] }}
                    </span>
                    <h1 class="display-hero mb-4 reveal">{!! $hero['title_html'] !!}</h1>
                    <p class="lead-soft mb-4 reveal">{{ $hero['lead'] }}</p>

                    <div class="d-flex flex-wrap gap-3 mb-4 reveal">
                        <a href="{{ route('app.signup') }}" class="btn btn-glow btn-lg rounded-pill px-4" wire:navigate>
                            {{ $hero['primary_cta'] }}
                        </a>
                        <a href="{{ route('landing.pricing', ['locale' => $locale]) }}" class="btn btn-outline-soft btn-lg rounded-pill px-4" wire:navigate>
                            {{ $hero['secondary_cta'] }}
                        </a>
                    </div>

                    <div class="d-flex flex-wrap gap-2 reveal">
                        @foreach((array) ($hero['pills'] ?? []) as $pill)
                            <span class="mini-pill">{{ $pill }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="floating-ui reveal">
                        <div class="mock-window glass-card grid-shine">
                            <div class="panel-top">
                                <span class="dot red"></span>
                                <span class="dot yellow"></span>
                                <span class="dot green"></span>
                                <span class="ms-auto demo-badge">{{ $preview['live'] }}</span>
                            </div>

                            <div class="hero-grid mb-3">
                                <div class="analytics-card glass-card p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted-soft small">{{ $preview['latency'] }}</span>
                                        <span class="badge-soft">
                                            <i class="bi bi-lightning-charge"></i>
                                            {{ LandingContent::text('common.explore_tools') }}
                                        </span>
                                    </div>
                                    <div class="metric-number">{{ $preview['latency_value'] }}</div>
                                    <div class="hero-mini-chart mt-3"></div>
                                </div>

                                <div class="analytics-card glass-card p-3">
                                    <div class="text-muted-soft small mb-2">{{ $preview['confidence'] }}</div>
                                    <div class="metric-number">{{ $preview['confidence_value'] }}</div>
                                    <div class="code-lines mt-3">
                                        <div style="width:90%"></div>
                                        <div style="width:72%"></div>
                                        <div style="width:82%"></div>
                                        <div style="width:63%"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="glass-card p-3 mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <strong>{{ $preview['demo_title'] }}</strong>
                                    <span class="demo-badge">{{ $preview['demo_badge'] }}</span>
                                </div>
                                <div class="wave-bars">
                                    @foreach([24, 36, 18, 52, 72, 40, 58, 90, 46, 76, 24, 36, 18, 52, 72, 40, 58, 90, 46, 76, 34, 62, 28, 70, 24, 36, 18, 52, 72, 40, 58, 90, 46, 76, 34, 62, 28, 70] as $height)
                                        <span style="height:{{ $height }}px"></span>
                                    @endforeach
                                </div>
                            </div>

                            <div class="glass-card p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>{{ $preview['ocr_title'] }}</strong>
                                    <span class="demo-badge">{{ $preview['ocr_badge'] }}</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-soft align-middle mb-0 small">
                                        <tr>
                                            <th>{{ LandingContent::text('home.preview.table_headers.page') }}</th>
                                            <th>{{ LandingContent::text('home.preview.table_headers.status') }}</th>
                                            <th>{{ LandingContent::text('home.preview.table_headers.language') }}</th>
                                        </tr>
                                        @foreach((array) ($preview['table'] ?? []) as $row)
                                            <tr>
                                                <td>{{ $row['page'] }}</td>
                                                <td><span class="badge-soft">{{ $row['status'] }}</span></td>
                                                <td>{{ $row['language'] }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="row g-4">
                @foreach((array) $stats as $stat)
                    <div class="col-md-3">
                        <div class="stats-card glass-card reveal">
                            <div class="text-muted-soft mb-2">{{ $stat['title'] }}</div>
                            <div class="metric-number">{{ $stat['value'] }}</div>
                            <div class="small text-muted-soft">{{ $stat['copy'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <span class="section-badge mb-3">
                    <i class="bi bi-grid-1x2"></i>
                    {{ $toolsHeading['badge'] }}
                </span>
                <h2 class="section-title">{{ $toolsHeading['title'] }}</h2>
                <p class="lead-soft mx-auto">{{ $toolsHeading['copy'] }}</p>
            </div>

            <livewire:landing::components.tool-grid :key="'home-tools-grid-' . app()->getLocale()" :limit="6" />
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6 reveal">
                    <span class="section-badge mb-3">
                        <i class="bi bi-play-circle"></i>
                        {{ $demo['badge'] }}
                    </span>
                    <h2 class="section-title mb-3">{{ $demo['title'] }}</h2>
                    <p class="lead-soft">{{ $demo['copy'] }}</p>

                    <div class="d-grid gap-3 mt-4">
                        @foreach((array) ($demo['steps'] ?? []) as $index => $step)
                            <div class="step-item">
                                <div class="step-number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</div>
                                <div>
                                    <h5 class="mb-1">{{ $step['title'] }}</h5>
                                    <p class="text-muted-soft mb-0">{{ $step['copy'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-6 reveal">
                    <div class="demo-shell glass-card">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="glass-card p-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>{{ LandingContent::text('home.demo.shell.tts_title') }}</strong>
                                        <span class="demo-badge">{{ LandingContent::text('home.demo.shell.tts_badge') }}</span>
                                    </div>
                                    <div class="form-control mb-3 bg-transparent text-light border-secondary-subtle">{{ $demoSampleText }}</div>
                                    <button class="btn btn-glow rounded-pill">{{ LandingContent::text('common.get_started') }}</button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="glass-card p-3 h-100">
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>{{ LandingContent::text('home.demo.shell.asr_title') }}</strong>
                                        <span class="demo-badge">{{ LandingContent::text('home.demo.shell.asr_badge') }}</span>
                                    </div>
                                    <div class="code-lines">
                                        <div style="width:92%"></div>
                                        <div style="width:84%"></div>
                                        <div style="width:77%"></div>
                                        <div style="width:54%"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="glass-card p-3 h-100">
                                    <div class="d-flex justify-content-between mb-2">
                                        <strong>{{ LandingContent::text('home.demo.shell.ocr_title') }}</strong>
                                        <span class="demo-badge">{{ LandingContent::text('home.demo.shell.ocr_badge') }}</span>
                                    </div>
                                    <div class="screenshot-frame">
                                        <div class="screenshot-inner">
                                            <div class="glass-card p-3 mb-2 small">{{ $demo['cards']['ocr'] }}</div>
                                            <div class="glass-card p-3 mb-2 small">{{ $demo['cards']['asr'] }}</div>
                                            <div class="glass-card p-3 small">{{ $demo['cards']['tts'] }}</div>
                                        </div>
                                    </div>
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
            <div class="text-center mb-5 reveal">
                <span class="section-badge mb-3">
                    <i class="bi bi-shield-check"></i>
                    {{ LandingContent::text('home.reasons_heading.badge') }}
                </span>
                <h2 class="section-title">{{ LandingContent::text('home.reasons_heading.title') }}</h2>
            </div>

            <livewire:landing::components.feature-grid :items="$reasons" columns="col-md-6 col-xl-3" />
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6 reveal">
                    <span class="section-badge mb-3">
                        <i class="bi bi-diagram-3"></i>
                        {{ $workflow['badge'] }}
                    </span>
                    <h2 class="section-title mb-4">{{ $workflow['title'] }}</h2>
                    <div class="d-grid gap-4">
                        @foreach((array) ($workflow['steps'] ?? []) as $index => $step)
                            <div class="step-item">
                                <div class="step-number">{{ $index + 1 }}</div>
                                <div>
                                    <h5>{{ $step['title'] }}</h5>
                                    <p class="text-muted-soft mb-0">{{ $step['copy'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-6 reveal">
                    <div class="screenshot-frame grid-shine">
                        <div class="screenshot-inner d-flex flex-column gap-3">
                            @foreach((array) ($workflow['surface'] ?? []) as $item)
                                <div class="glass-card p-3 d-flex justify-content-between align-items-center">
                                    <span>{{ $item['label'] }}</span>
                                    <span class="{{ str_contains(strtolower($item['value']), 'txt') ? 'demo-badge' : (str_contains(strtolower($item['value']), 'running') ? 'badge-soft' : 'kbd-soft') }}">
                                        {{ $item['value'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <span class="section-badge mb-3">
                    <i class="bi bi-cash-coin"></i>
                    {{ $pricingHeading['badge'] }}
                </span>
                <h2 class="section-title">{{ $pricingHeading['title'] }}</h2>
            </div>

            <livewire:landing::components.pricing-grid :codes="['free', 'pro', 'premium']" :show-toggle="false" :show-ancillary-sections="false" />
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-5">
                    <div class="testimonial-card glass-card reveal h-100">
                        <span class="section-badge mb-3">
                            <i class="bi bi-chat-quote"></i>
                            {{ $testimonial['badge'] }}
                        </span>
                        <h3 class="mb-3">{{ $testimonial['quote'] }}</h3>
                        <p class="text-muted-soft">{{ $testimonial['copy'] }}</p>
                        <div class="mt-4">
                            <strong>{{ $testimonial['author'] }}</strong>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="glass-card p-4 reveal h-100">
                        <span class="section-badge mb-3">
                            <i class="bi bi-question-circle"></i>
                            {{ $faqHeading['badge'] }}
                        </span>
                        <div class="row g-4">
                            @foreach((array) $faqs as $faq)
                                <div class="col-md-6">
                                    <div class="faq-item glass-card">
                                        <h4>{{ $faq['title'] }}</h4>
                                        <p class="text-muted-soft mb-0">{{ $faq['copy'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

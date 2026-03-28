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
@endphp

<x-slot:title>{{ LandingContent::text('pricing_page.meta.title') }}</x-slot:title>
<x-slot:description>{{ LandingContent::text('pricing_page.meta.description') }}</x-slot:description>
<x-slot:keywords>{{ LandingContent::text('pricing_page.meta.keywords') }}</x-slot:keywords>

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
</div>

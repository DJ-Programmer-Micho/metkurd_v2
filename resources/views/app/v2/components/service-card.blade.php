@props(['service', 'slug'])
<div class="turbo-border h-100">
    <div class="turbo-inner h-100">
        <a wire:navigate href="{{ route('app.v2.service', ['locale' => app()->getLocale(), 'service' => $slug]) }}" class="glass-load glass-load--{{ $service['color'] }} v2-service-card h-100">
            <img class="v2-service-icon" src="{{ asset($service['icon_asset']) }}" alt="">
            <h2 class="h4 mt-4 mb-2">{{ __($service['name']) }}</h2>
            <p class="v2-muted mb-3">{{ __($service['description']) }}</p>
            <span class="v2-service-cta small fw-semibold">{{ __('Explore service') }} <i class="ri-arrow-right-up-line"></i></span>
        </a>
    </div>
</div>

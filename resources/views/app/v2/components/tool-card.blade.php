@props(['service', 'serviceSlug', 'tool', 'toolSlug'])
@if (($tool['coming_soon'] ?? false) === true)
    <div class="glass-load glass-load--secondary v2-tool-panel opacity-75" aria-disabled="true">
        <span class="v2-tool-index">--</span><div><span class="badge text-bg-secondary mb-1">{{ __('Coming soon') }}</span><h2 class="h6 mb-0">{{ __($tool['name']) }}</h2></div>
    </div>
@else
    <a wire:navigate href="{{ route('app.v2.tool', ['locale' => app()->getLocale(), 'service' => $serviceSlug, 'tool' => $toolSlug]) }}" class="glass-load glass-load--{{ $service['color'] }} v2-tool-panel">
        <span class="v2-tool-index">{{ str_pad((string) ($loopIndex ?? 1), 2, '0', STR_PAD_LEFT) }}</span>
        <div><div class="small text-{{ $service['color'] }} text-uppercase fw-semibold mb-1">{{ __($service['name']) }}</div><h2 class="h6 mb-0">{{ __($tool['name']) }}</h2></div>
        <i class="ri-arrow-right-line v2-tool-arrow"></i>
    </a>
@endif

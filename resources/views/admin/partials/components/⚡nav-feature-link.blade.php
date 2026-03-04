{{-- resources/views/app/partials/components/nav-feature-link.blade.php --}}
<?php

use Livewire\Component;

new class extends Component
{
    public ?string $route = null;
    public string $icon = '';
    public string $label = '';
    public ?string $feature = null;
    public ?string $badge = null;
};
?>

@php
    $user = auth('app')->user();
    $enabled = true;
    // $enabled = $feature ? ($user?->hasFeature($feature) ?? false) : true;

    $url = $enabled && $route
        ? route($route, ['locale' => app()->getLocale()])
        : 'javascript:void(0)';
@endphp

<li class="nav-item">
    <a
        class="nav-link menu-link {{ $enabled ? '' : 'disabled' }}"
        href="{{ $url }}"
        @if($enabled)
            wire:navigate.hover
        @else
            tabindex="-1" aria-disabled="true"
            data-bs-toggle="tooltip"
            data-bs-placement="right"
            title="Subscribe to unlock this feature"
        @endif
    >
        <i class="{{ $icon }}"></i>
        <span style="{{ $enabled ? '' : 'opacity:0.6;' }}">{{ $label }}</span>

        @if(!$enabled && $badge)
            <span class="badge badge-pill bg-primary">{{ $badge }}</span>
        @endif
    </a>
</li>
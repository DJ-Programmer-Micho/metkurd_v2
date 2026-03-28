{{-- resources/views/admin/partials/components/nav-multi-feature-link.blade.php --}}
<?php

use Livewire\Component;

new class extends Component
{
    public ?string $id = null;
    public string $icon = '';
    public string $label = '';
    public array|string|null $features = null;
    public string $mode = 'any'; // any|all
    public ?string $badge = null;
    public string $tooltip = 'Subscribe to unlock this feature';
};
?>

@php
    $user = auth('admin')->user();

    $featuresArr = collect(is_array($features) ? $features : ($features ? [$features] : []))
        ->filter()
        ->values()
        ->all();

    if (empty($featuresArr)) {
        $enabled = true;
    } else {
        $checks = array_map(fn($f) => (bool) ($user?->hasFeature($f) ?? false), $featuresArr);
        $enabled = ($mode === 'all')
            ? !in_array(false, $checks, true)
            : in_array(true, $checks, true);
    }

    $collapseId = $id ?: ('sidebarMenu_' . (\Illuminate\Support\Str::slug($label) ?: 'menu'));
@endphp

<li class="nav-item">
    <a
        class="nav-link menu-link {{ $enabled ? '' : 'disabled' }}"
        href="{{ $enabled ? ('#'.$collapseId) : 'javascript:void(0)' }}"
        @if($enabled) data-bs-toggle="collapse" @endif
        role="button"
        aria-expanded="false"
        aria-controls="{{ $collapseId }}"
        @if(!$enabled)
            tabindex="-1" aria-disabled="true"
            data-bs-toggle="tooltip"
            data-bs-placement="right"
            title="{{ __($tooltip) }}"
        @endif
    >
        <i class="{{ $icon }}"></i>
        <span style="{{ $enabled ? '' : 'opacity:0.6;' }}">{{ $label }}</span>

        @if(!$enabled && $badge)
            <span class="badge badge-pill bg-primary ms-2">{{ $badge }}</span>
        @endif
    </a>

    <div class="collapse menu-dropdown" id="{{ $collapseId }}">
        <ul class="nav nav-sm flex-column">
            {{ $slot }}
        </ul>
    </div>
</li>

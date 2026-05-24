{{-- resources/views/app/partials/components/nav-multi-feature-link.blade.php --}}
<?php

use Livewire\Component;

new class extends Component
{
    public ?string $id = null;
    public string $icon = '';
    public string $label = '';
    public ?string $description = null;
    public array|string|null $features = null;
    public array|string|null $toolCodes = null;
    public array|string|null $entitlements = null;
    public array|string|null $activeRoutes = null;
    public string $mode = 'any'; // any|all
    public ?string $badge = null;
    public string $tooltip = 'Subscribe to unlock this feature';
};
?>

@php
    $customer = auth('app')->user();

    $normalize = function (array|string|null $codes): array {
        return collect(is_array($codes) ? $codes : ($codes !== null ? [$codes] : []))
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->filter()
            ->values()
            ->all();
    };

    $legacyFeatures = collect($normalize($features));

    $toolChecks = collect($normalize($toolCodes))
        ->merge(
            $legacyFeatures
                ->filter(fn ($code) => \Illuminate\Support\Str::endsWith($code, '.active'))
                ->map(fn ($code) => \Illuminate\Support\Str::beforeLast($code, '.active'))
        )
        ->map(fn ($code) => $code === 'wasr' ? 'asr' : $code)
        ->unique()
        ->values()
        ->all();

    $entitlementChecks = collect($normalize($entitlements))
        ->merge(
            $legacyFeatures->filter(fn ($code) => ! \Illuminate\Support\Str::endsWith($code, '.active'))
        )
        ->unique()
        ->values()
        ->all();

    if (empty($toolChecks) && empty($entitlementChecks)) {
        $enabled = true;
    } else {
        $checks = [
            ...array_map(fn ($code) => (bool) ($customer?->canAccessTool($code) ?? false), $toolChecks),
            ...array_map(fn ($code) => (bool) ($customer?->isAllowed($code) ?? false), $entitlementChecks),
        ];

        $enabled = ($mode === 'all')
            ? !in_array(false, $checks, true)
            : in_array(true, $checks, true);
    }

    $collapseId = $id ?: ('sidebarMenu_' . (\Illuminate\Support\Str::slug($label) ?: 'menu'));
    $currentRouteName = request()->route()?->getName();
    $activeChecks = collect($normalize($activeRoutes))->unique()->values()->all();
    $isActive = $currentRouteName !== null && in_array($currentRouteName, $activeChecks, true);
@endphp

<li
    @class([
        'nav-item',
        'd-none' => ! $enabled,
    ])
    @if(! $enabled)
        hidden
        aria-hidden="true"
    @endif
>
    @if($enabled)
        <a
            class="nav-link menu-link {{ $isActive ? 'active' : '' }}"
            href="#{{ $collapseId }}"
            data-bs-toggle="collapse"
            role="button"
            aria-expanded="{{ $isActive ? 'true' : 'false' }}"
            aria-controls="{{ $collapseId }}"
            title="{{ __($label) }}"
            aria-label="{{ __($label) }}"
        >
            <i class="{{ $icon }}"></i>
            <span class="nav-link-content">
                <span class="nav-link-title">{{ __($label) }}</span>
                @if($description)
                    <small class="nav-link-description">{{ __($description) }}</small>
                @endif
            </span>

            @if($badge)
                <span class="badge badge-pill bg-primary ms-2">{{ __($badge) }}</span>
            @endif
        </a>

        <div class="collapse menu-dropdown nav-multi-feature-dropdown {{ $isActive ? 'show' : '' }}" id="{{ $collapseId }}">
            <ul class="nav nav-sm flex-column nav-multi-feature-child-list">
                {{ $slot }}
            </ul>
        </div>
    @endif
</li>

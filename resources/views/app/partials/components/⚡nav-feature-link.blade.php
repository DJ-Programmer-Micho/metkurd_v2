{{-- resources/views/app/partials/components/nav-feature-link.blade.php --}}
<?php

use Livewire\Component;

new class extends Component
{
    public ?string $route = null;

    public string $icon = '';

    public string $label = '';

    public ?string $description = null;

    public ?string $feature = null;

    public array|string|null $toolCodes = null;

    public array|string|null $entitlements = null;

    public array|string|null $activeRoutes = null;

    public string $mode = 'any';

    public ?string $badge = null;
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

    $legacyFeature = strtolower(trim((string) $feature));

    $toolChecks = collect($normalize($toolCodes));
    $entitlementChecks = collect($normalize($entitlements));

    if ($legacyFeature !== '') {
        if (\Illuminate\Support\Str::endsWith($legacyFeature, '.active')) {
            $toolChecks->push(\Illuminate\Support\Str::beforeLast($legacyFeature, '.active'));
        } else {
            $entitlementChecks->push($legacyFeature);
        }
    }

    $toolChecks = $toolChecks
        ->map(fn ($code) => $code === 'wasr' ? 'asr' : $code)
        ->unique()
        ->values()
        ->all();

    $entitlementChecks = $entitlementChecks
        ->unique()
        ->values()
        ->all();

    if (empty($toolChecks) && empty($entitlementChecks)) {
        $enabled = true;
    } else {
        $checks = [
            ...array_map(fn ($code) => (bool) ($customer?->canAccessTool($code, channel: \App\Models\PlanEntitlement::CHANNEL_APP) ?? false), $toolChecks),
            ...array_map(fn ($code) => (bool) ($customer?->isAllowed($code, \App\Models\PlanEntitlement::CHANNEL_APP) ?? false), $entitlementChecks),
        ];

        $enabled = $mode === 'all'
            ? ! in_array(false, $checks, true)
            : in_array(true, $checks, true);
    }

    $url = $route ? route($route, ['locale' => app()->getLocale()]) : 'javascript:void(0)';
    $currentRouteName = request()->route()?->getName();
    $activeChecks = collect($normalize($activeRoutes))
        ->merge($route ? [$route] : [])
        ->unique()
        ->values()
        ->all();
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
            href="{{ $url }}"
            wire:navigate.hover
            title="{{ __($label) }}"
            aria-label="{{ __($label) }}"
            @if($isActive) aria-current="page" @endif
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
    @endif
</li>

{{-- resources/views/app/partials/components/nav-multi-feature-link.blade.php --}}
<?php

use Livewire\Component;

new class extends Component
{
    public ?string $id = null;
    public string $icon = '';
    public string $label = '';
    public array|string|null $features = null;
    public array|string|null $toolCodes = null;
    public array|string|null $entitlements = null;
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
            class="nav-link menu-link"
            href="#{{ $collapseId }}"
            data-bs-toggle="collapse"
            role="button"
            aria-expanded="false"
            aria-controls="{{ $collapseId }}"
        >
            <i class="{{ $icon }}"></i>
            <span>{{ $label }}</span>

            @if($badge)
                <span class="badge badge-pill bg-primary ms-2">{{ $badge }}</span>
            @endif
        </a>

        <div class="collapse menu-dropdown" id="{{ $collapseId }}">
            <ul class="nav nav-sm flex-column">
                {{ $slot }}
            </ul>
        </div>
    @endif
</li>

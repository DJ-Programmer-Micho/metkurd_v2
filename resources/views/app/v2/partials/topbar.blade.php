@php
    $shell = app(\App\Support\AppShellData::class)->forCurrentCustomer();
    $apiAccessEnabled = auth('app')->check() && app(\App\Services\CustomerApi\V2\ApiCatalog::class)->scopes(auth('app')->user()) !== [];
    $apiRoute = route('app.v2.api', ['locale' => app()->getLocale()]);
    $profile = $shell['profile'] ?? null;
    $fallbackAvatarUrl = app(\App\Support\AvatarFallbackUrl::class)->customer();
    $avatarUrl = $profile?->avatar_url ?: $fallbackAvatarUrl;
    $customerName = trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')) ?: __('Account');
@endphp

<header class="v2-topbar sticky-top">
    <div class="container-fluid px-3 px-lg-4 py-2 d-flex align-items-center gap-2 gap-lg-3">
        <a wire:navigate href="{{ route('app.v2.home', ['locale' => app()->getLocale()]) }}" class="text-decoration-none text-white fw-semibold d-flex align-items-center gap-2">
            <img class="v2-brand-logo" src="{{ asset('app/logo/white_logo.svg') }}" alt="{{ __('MetKurd AI') }}">
            <span class="d-none d-sm-inline">{{ __('MetKurd AI') }}</span>
        </a>

        <nav class="ms-auto d-flex align-items-center gap-1 gap-lg-2">
            <div class="dropdown" wire:ignore>
                <button type="button" class="btn btn-sm btn-outline-light border-0 v2-topbar-control" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Interface language') }}">
                    <img src="{{ asset('lang/'.app()->getLocale().'.png') }}" alt="" width="18" height="18" class="rounded me-1"><span class="d-none d-md-inline">{{ strtoupper(app()->getLocale()) }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    @foreach(config('app.locales') as $locale)
                        <button type="button" class="dropdown-item d-flex align-items-center gap-2" onclick="window.metkurdV2SetLocale('{{ $locale }}')"><img src="{{ asset('lang/'.$locale.'.png') }}" alt="" width="20" height="20" class="rounded">{{ __(strtoupper($locale)) }}</button>
                    @endforeach
                </div>
            </div>

            <a wire:navigate href="{{ $apiRoute }}" class="btn btn-sm {{ $apiAccessEnabled ? 'btn-info' : 'btn-outline-secondary' }} v2-topbar-control" aria-label="{{ __('Open API Access') }}"><i class="mdi mdi-api"></i><span class="d-none d-md-inline ms-1">{{ __('API') }}</span></a>

            <div class="v2-process-slots"><livewire:partials.process-slots /></div>

            <div class="dropdown" wire:ignore>
                <button type="button" class="btn btn-sm btn-outline-light border-0 d-flex align-items-center gap-2 v2-topbar-control" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('account_v2.account') }}">
                    <img class="rounded-circle" width="30" height="30" style="object-fit:cover" src="{{ $avatarUrl }}" data-avatar-fallback="{{ $fallbackAvatarUrl }}" onerror="this.onerror=null;this.src=this.dataset.avatarFallback" alt="">
                    <span class="d-none d-xl-inline">{{ $customerName }}</span><i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <h6 class="dropdown-header">{{ __('Welcome') }} {{ $profile?->first_name ?? __('Customer') }}</h6>
                    @foreach(['profile' => 'Profile', 'billing' => 'account_v2.billing_title', 'subscription-plans' => 'purchase_v2.service_title', 'storage-plans' => 'purchase_v2.storage_title', 'addon-credits' => 'purchase_v2.addon_title', 'storage' => 'My Storage'] as $page => $label)
                        <a wire:navigate class="dropdown-item {{ request()->routeIs('app.v2.'.$page) ? 'active' : '' }}" @if(request()->routeIs('app.v2.'.$page)) aria-current="page" @endif href="{{ route('app.v2.'.$page, ['locale' => app()->getLocale()]) }}">{{ __($label) }}</a>
                    @endforeach
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item" type="button" onclick="document.getElementById('v2-logout-form').submit()"><i class="mdi mdi-logout me-1"></i>{{ __('Logout') }}</button>
                </div>
            </div>
        </nav>
    </div>
</header>

@php
    $shell = app(\App\Support\AppShellData::class)->forCurrentCustomer();
    $apiAccessEnabled = (bool) ($shell['api_access_enabled'] ?? false);
    $apiRoute = $apiAccessEnabled
        ? route('app.api-access', ['locale' => app()->getLocale()])
        : route('subscription-plan', ['locale' => app()->getLocale()]);
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

            <a wire:navigate href="{{ $apiRoute }}" class="btn btn-sm {{ $apiAccessEnabled ? 'btn-info' : 'btn-outline-secondary' }} v2-topbar-control" aria-label="{{ $apiAccessEnabled ? __('Open API Access') : __('View API subscription options') }}"><i class="mdi mdi-api"></i><span class="d-none d-md-inline ms-1">{{ __('API') }}</span></a>

            <div class="v2-process-slots"><livewire:partials.process-slots /></div>

            <div class="dropdown" wire:ignore>
                <button type="button" class="btn btn-sm btn-outline-light border-0 d-flex align-items-center gap-2 v2-topbar-control" data-bs-toggle="dropdown" aria-expanded="false">
                    <img class="rounded-circle" width="30" height="30" style="object-fit:cover" src="{{ $avatarUrl }}" data-avatar-fallback="{{ $fallbackAvatarUrl }}" onerror="this.onerror=null;this.src=this.dataset.avatarFallback" alt="">
                    <span class="d-none d-xl-inline">{{ $customerName }}</span><i class="ri-arrow-down-s-line"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end"><h6 class="dropdown-header">{{ __('Welcome') }} {{ $profile?->first_name ?? __('Customer') }}</h6><a wire:navigate class="dropdown-item" href="{{ route('app.profile', ['locale' => app()->getLocale()]) }}"><i class="mdi mdi-account-circle me-1"></i>{{ __('Profile') }}</a><a wire:navigate class="dropdown-item" href="{{ route('app.v2.storage', ['locale' => app()->getLocale()]) }}"><i class="mdi mdi-database-outline me-1"></i>{{ __('My Storage') }}</a><a wire:navigate class="dropdown-item" href="{{ route('app.billing', ['locale' => app()->getLocale()]) }}"><i class="mdi mdi-chart-pie me-1"></i>{{ __('Billing') }}</a><div class="dropdown-divider"></div><button class="dropdown-item" type="button" onclick="document.getElementById('v2-logout-form').submit()"><i class="mdi mdi-logout me-1"></i>{{ __('Logout') }}</button></div>
            </div>
        </nav>
    </div>
</header>

{{-- resources/views/app/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"
      data-layout="vertical"
      data-topbar="light"
      data-sidebar-size="lg"
      data-sidebar="dark"
      data-sidebar-image="none"
      data-preloader="disable"
      data-sidebar-visibility="show"
      data-layout-style="default"
      data-bs-theme="dark"
      data-layout-width="fluid"
      data-layout-position="fixed">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="theme-color" content="#cc0022">
    <meta name="publisher" content="MET IRAQ">
    <meta name="mobile-web-app-title" content="MET KURD">
    <meta name="author" content="Michel Shabo">
    <meta name="robots" content="index, follow">

    <link rel="shortcut icon" href="{{ app('logo_1024_tran') }}">

    {{-- <link href="{{ asset('app/libs/swiper/swiper-bundle.min.css') }}" rel="stylesheet" type="text/css" /> --}}

    <link href="{{ asset('app/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/app.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('general/css/toaster.css') }}" rel="stylesheet" type="text/css">

    <title>{{ $title ?? 'APP | METKURD' }}</title>

    @stack('styles')
    @vite('resources/js/app.js')
    @livewireStyles

    <style>.ar-shift{direction:rtl;text-align:right;}</style>

    {{-- keep layout.js here if it only sets html data-attributes --}}
    <script src="{{ asset('app/js/layout.js') }}"></script>

    {{-- FontAwesome (ok) --}}
    {{-- <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/all.min.js"
            integrity="sha512-6sSYj..."
            crossorigin="anonymous" referrerpolicy="no-referrer"></script> --}}
</head>

<body>
    @include('admin.partials.header-one')

    @if (view()->exists('admin.partials.navbar-one'))
        @include('admin.partials.navbar-one')
    @endif

    <div class="vertical-overlay"></div>

    <div class="main-content">
        {{ $slot }}

        @if (view()->exists('admin.partials.footer-one'))
            @include('admin.partials.footer-one')
        @endif
    </div>

    {{-- Core JS (Bootstrap must be before plugins that depend on it) --}}
    <script data-navigate-once src="{{ asset('app/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/simplebar/simplebar.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/node-waves/waves.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/feather-icons/feather.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/js/pages/plugins/lord-icon-2.1.0.js') }}"></script>

    <script src="{{ asset('app/libs/swiper/swiper-bundle.min.js') }}"></script>

    {{-- Template scripts --}}
    {{-- <script src="{{ asset('app/js/plugins.js') }}"></script> --}}
    <script data-navigate-once src="{{ asset('app/js/app.js') }}"></script>

    {{-- Toastr --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

    @livewireScripts

    {{-- Put stack after Livewire so page scripts can hook into events safely --}}
    @stack('scripts')

    {{-- Logout + Language Forms --}}
    <form id="logout-form"
          action="{{ route('admin.logout', ['locale' => app()->getLocale()]) }}"
          method="POST" class="d-none">
        @csrf
    </form>

    <form id="languageForm" action="{{ route('setLocale') }}" method="POST" class="d-none">
        @csrf
        <input type="hidden" name="locale" id="selectedLocale" value="{{ app()->getLocale() }}">
    </form>

<script>
    // Language switch
    function changeLanguage(locale) {
        document.getElementById('selectedLocale').value = locale;
        document.getElementById('languageForm').submit();
    }

    // Toastr from Livewire
    window.addEventListener('alert', (event) => {
        toastr.options = { closeButton: true, progressBar: true };
        toastr[event.detail.type](event.detail.message, event.detail.title ?? '');
    });

    function initBootstrap() {
        // Dropdowns
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((el) => {
            const existing = bootstrap.Dropdown.getInstance(el);
            if (existing) existing.dispose();
            new bootstrap.Dropdown(el);
        });

        // Tooltips
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
            const existing = bootstrap.Tooltip.getInstance(el);
            if (existing) existing.dispose();
            new bootstrap.Tooltip(el);
        });

        // Feather icons
        if (typeof feather !== 'undefined') feather.replace();

        // Waves
        if (typeof Waves !== 'undefined') Waves.init();
    }

    // Full page load
    document.addEventListener('DOMContentLoaded', initBootstrap);

    // wire:navigate page swap — dispose first, then reinit after DOM settles
    document.addEventListener('livewire:navigating', () => {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((el) => {
            bootstrap.Dropdown.getInstance(el)?.dispose();
        });
    });

    document.addEventListener('livewire:navigated', () => {
        requestAnimationFrame(() => initBootstrap());
    });
</script>
</body>
</html>
{{-- resources/views/app/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'ku'], true) ? 'rtl' : 'ltr' }}"
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
    <meta name="mobile-web-app-title" content="Met Kurd AI">
    <meta name="author" content="Michel Mikhael">
    <meta name="robots" content="noindex, nofollow">

    <link rel="shortcut icon" href="{{ app('logo_1024_tran') }}">

    {{-- <link href="{{ asset('app/libs/swiper/swiper-bundle.min.css') }}" rel="stylesheet" type="text/css" /> --}}
    @stack('pre-styles')
    <link href="{{ asset('app/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/app.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('general/css/toaster.css') }}" rel="stylesheet" type="text/css">

    <title>{{ $title ?? __('APP | METKURD') }}</title>

    <link href="{{ asset('admin/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet">
    @stack('styles')
    @vite(['resources/js/app.js', 'resources/js/admin.js'])
    @livewireStyles

    <style>.ar-shift{direction:rtl;text-align:right;}</style>

    {{-- keep layout.js here if it only sets html data-attributes --}}
    {{-- Admin owns its fixed shell; customer/template layout preferences do not apply. --}}

    {{-- FontAwesome (ok) --}}
    {{-- <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/all.min.js"
            integrity="sha512-6sSYj..."
            crossorigin="anonymous" referrerpolicy="no-referrer"></script> --}}
</head>

<body class="admin-shell">
    <a class="admin-skip btn btn-primary" data-admin-drawer-background href="#admin-main">{{ __('admin_p3.skip') }}</a>
    <span hidden data-admin-ui data-reason="{{ __('admin_p0.change_reason') }}" data-reason-required="{{ __('admin_cleanup.reason_required') }}" data-forbidden="{{ __('admin_p3.forbidden') }}" data-pagination="{{ __('Pagination Navigation') }}" data-confirm="{{ __('admin_p3.confirm') }}" data-cancel="{{ __('Cancel') }}" data-close="{{ __('Close') }}" data-failed="{{ __('admin_p0.request_failed') }}" data-table-region="{{ __('admin_shell.table_region') }}"></span>
    @include('admin.partials.header-one')

    @if (view()->exists('admin.partials.navbar-one'))
        @include('admin.partials.navbar-one')
    @endif

    <button type="button" class="admin-sidebar-overlay" data-admin-sidebar-close tabindex="-1" aria-label="{{ __('Close') }}"></button>

    <div class="main-content" data-admin-drawer-background>
        <main id="admin-main" class="page-content" tabindex="-1">
        @php
            $adminContext = \App\Support\Admin\AdminNavigation::context();
        @endphp
        <nav class="admin-breadcrumb" aria-label="{{ __('admin_shell.breadcrumb') }}">
            <a wire:navigate href="{{ route('admin.home', ['locale' => app()->getLocale()]) }}">{{ __('admin_shell.brand') }}</a>
            <span aria-hidden="true">/</span><span data-admin-page-group>{{ __('admin_shell.'.$adminContext['group']) }}</span>
            <span aria-hidden="true">/</span><span aria-current="page" data-admin-page-context>{{ __('admin_shell.'.$adminContext['key']) }}</span>
        </nav>
        {{ $slot }}
        </main>
        @if (view()->exists('admin.partials.footer-one'))
            @include('admin.partials.footer-one')
        @endif
    </div>

    {{-- Core JS (Bootstrap must be before plugins that depend on it) --}}
    <script data-navigate-once src="{{ asset('admin/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('admin/libs/simplebar/simplebar.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('admin/libs/node-waves/waves.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('admin/libs/feather-icons/feather.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('admin/js/pages/plugins/lord-icon-2.1.0.js') }}"></script>

    <script data-navigate-once src="{{ asset('app/libs/swiper/swiper-bundle.min.js') }}"></script>

    {{-- Template scripts --}}
    {{-- <script src="{{ asset('app/js/plugins.js') }}"></script> --}}
    {{-- Navigation and disposal are owned by resources/js/admin.js. --}}

    {{-- Toastr --}}
    <script data-navigate-once src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script data-navigate-once src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

    <script data-navigate-once src="{{ asset('admin/libs/sweetalert2/sweetalert2.min.js') }}"></script>
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


</body>
</html>

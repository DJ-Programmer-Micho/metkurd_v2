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

    {{-- <link rel="stylesheet" href="{{ asset('main/assets/vendor/line-awesome/line-awesome/line-awesome/css/line-awesome.min.css') }}"> --}}
    <link href="{{ asset('app/libs/swiper/swiper-bundle.min.css') }}" rel="stylesheet" type="text/css" />
    <script src="{{ asset('app/js/layout.js') }}"></script>

    <link href="{{ asset('app/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/app.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/custom.min.css') }}" rel="stylesheet" type="text/css" />

    <meta name="theme-color" content="#cc0022">
    <meta name="publisher" content="MET IRAQ">
    <meta name="mobile-web-app-title" content="{{ __('MET KURD') }}">
    <meta name="author" content="Michel Mikhael">
    <meta name="robots" content="index, follow">

    <link rel="shortcut icon" href="{{ app('logo_1024_tran') }}">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/js/all.min.js"
            integrity="sha512-6sSYJqDreZRZGkJ3b+YfdhB3MzmuP9R7X1QZ6g5aIXhRvR1Y/N/P47jmnkENm7YL3oqsmI6AK+V6AD99uWDnIw=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <link href="{{ asset('general/css/toaster.css') }}" rel="stylesheet" type="text/css">
    <script src="https://cdn.lordicon.com/lordicon.js"></script>

    <title>{{ $title ?? __('Authentication') . ' | ' . __('MET KURD') }}</title>
<style>
    
</style>
    @stack('styles')
    @livewireStyles

    <style>
        .ar-shift { direction: rtl; text-align: right; }
        .auth-one-bg { background-image: url('https://images.pexels.com/photos/34303478/pexels-photo-34303478.jpeg') !important; }
        .shape { z-index: 1; }
        .auth-one-bg .bg-overlay {
            background: linear-gradient(-45deg, #cc002242, #0e11172d);
            opacity: .6;
        }
        .auth-bg-cover { background: linear-gradient(135deg, #0e1117 30%, rgb(204, 0, 34)); }
        .card { z-index: 2; }
    </style>
</head>

<body>
<div class="auth-page-wrapper auth-bg-cover py-5 d-flex justify-content-center align-items-center min-vh-100">
    <div class="bg-overlay"></div>

    <div class="auth-page-content overflow-hidden pt-lg-5">
        <div class="container">
            {{-- Header / logo slot (keep your blade component if you want) --}}
            @if (view()->exists('app.partials.auth-header-one'))
                @include('app.partials.auth-header-one')
            @endif

            {{ $slot }}
        </div>
    </div>
</div>

<script src="{{ asset('app/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('app/libs/simplebar/simplebar.min.js') }}"></script>
<script src="{{ asset('app/libs/node-waves/waves.min.js') }}"></script>
<script src="{{ asset('app/libs/feather-icons/feather.min.js') }}"></script>
<script src="{{ asset('app/js/pages/plugins/lord-icon-2.1.0.js') }}"></script>
<script src="{{ asset('app/js/plugins.js') }}"></script>

<script src="{{ asset('app/libs/swiper/swiper-bundle.min.js') }}"></script>
<script src="{{ asset('app/js/app.js') }}"></script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

@stack('scripts')
@livewireScripts

<script>
    window.addEventListener('alert', event => {
        toastr[event.detail.type](event.detail.message, event.detail.title ?? '');
        toastr.options = { closeButton: true, progressBar: true };
    });
</script>
</body>
</html>

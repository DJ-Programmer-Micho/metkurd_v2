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

    <link href="{{ asset('app/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/app.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('general/css/toaster.css') }}" rel="stylesheet" type="text/css">

    <title>{{ $title ?? 'APP | METKURD' }}</title>

    @vite('resources/js/app.js')
    @livewireStyles
    @stack('styles')

    <style>
        .turbo-border{
            position: relative;
            border-radius: 0.25rem;
            padding: 1px;
            isolation: isolate;
            --a: 0turn;
            background: conic-gradient(
                from var(--a),
                rgba(255, 255, 255, 0) 18%,
                rgba(237, 32, 36,.85),
                rgba(254, 189, 17),
                rgba(39, 142, 67,.85),
                rgba(255, 0, 0, 0) 82%
            );
            animation: turboSpin 3.2s linear infinite;
        }

        .turbo-inner{
            border-radius: 0.25rem;
            background: transparent;
        }

        .turbo-border::before{
            content:"";
            position:absolute;
            border-radius: 0.25rem;
            background: inherit;
            filter: blur(18px);
            opacity: .15;
            z-index: -1;
        }

        @keyframes turboSpin{
            to { --a: 1turn; }
        }

        @property --a {
            syntax: "<angle>";
            inherits: false;
            initial-value: 0turn;
        }

        .glass-load{
            --base: #242526;
            --highlight: #3a3b3c;
            --edge: #2e2f30;

            background: linear-gradient(
                110deg,
                var(--base) 30%,
                var(--highlight) 38%,
                var(--highlight) 40%,
                var(--base) 48%
            );
            background-size: 200% 100%;
            background-position: 100% 0;
            animation: load 1.5s infinite linear;

            border-radius: 14px;
            border: 1px solid rgba(255,255,255,.06);
            box-shadow: 0 6px 18px rgba(0,0,0,.35);
            color: inherit;
        }

        .glass-load--info{
            --highlight: rgba(0, 123, 255, .35);
            border-color: rgba(0, 123, 255, .25);
        }

        .glass-load--warning{
            --highlight: rgba(255, 193, 7, .35);
            border-color: rgba(255, 193, 7, .25);
        }

        .glass-load--success{
            --highlight: rgba(40, 167, 69, .35);
            border-color: rgba(40, 167, 69, .25);
        }

        .glass-load--danger{
            --highlight: rgba(220, 53, 69, .35);
            border-color: rgba(220, 53, 69, .25);
        }

        .glass-load--secondary{
            --highlight: rgba(108, 117, 125, .30);
            border-color: rgba(255,255,255,.08);
        }

        @keyframes load {
            to { background-position: -100% 0; }
        }

        .tts-status-muted{ color: rgba(255,255,255,.65) !important; }
        .tts-status-id{ color: rgba(255,255,255,.85) !important; }

        /* // NAVBAR NAV CONTROL */
        .simplebar-offset{
            bottom: 0px !important;
        }

        .ar-shift{
            direction: rtl;
            text-align: right;
        }
    </style>

    <script data-navigate-once src="{{ asset('app/js/layout.js') }}"></script>
</head>

<body>
    <div id="layout-wrapper">
        @include('app.partials.header-one')

        @if (view()->exists('app.partials.navbar-one'))
            @include('app.partials.navbar-one')
        @endif

        <div class="vertical-overlay"></div>

        <div class="main-content">
            <div class="page-content">
                {{ $slot }}
            </div>

            @if (view()->exists('app.partials.footer-one'))
                @include('app.partials.footer-one')
            @endif
        </div>
    </div>

    {{-- Core vendor JS --}}
    <script data-navigate-once src="{{ asset('app/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/simplebar/simplebar.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/node-waves/waves.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/feather-icons/feather.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/js/pages/plugins/lord-icon-2.1.0.js') }}"></script>
    <script data-navigate-once src="{{ asset('app/libs/swiper/swiper-bundle.min.js') }}"></script>

    {{-- Theme/app script --}}
    <script data-navigate-once src="{{ asset('app/js/app.js') }}"></script>

    {{-- Toastr --}}
    <script data-navigate-once src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

    @livewireScripts
    @stack('scripts')

    <form id="logout-form"
          action="{{ route('app.logout', ['locale' => app()->getLocale()]) }}"
          method="POST"
          class="d-none">
        @csrf
    </form>

    <form id="languageForm" action="{{ route('setLocale') }}" method="POST" class="d-none">
        @csrf
        <input type="hidden" name="locale" id="selectedLocale" value="{{ app()->getLocale() }}">
    </form>

    <script data-navigate-once>
        (function () {
            'use strict';

            if (window.__APP_LAYOUT_BOOTED__) return;
            window.__APP_LAYOUT_BOOTED__ = true;

            function changeLanguage(locale) {
                const input = document.getElementById('selectedLocale');
                const form = document.getElementById('languageForm');

                if (!input || !form) return;

                input.value = locale;
                form.submit();
            }

            window.changeLanguage = changeLanguage;

            function initToastrListener() {
                if (window.__APP_TOASTR_BOUND__) return;
                window.__APP_TOASTR_BOUND__ = true;

                window.addEventListener('alert', (event) => {
                    if (typeof toastr === 'undefined') return;

                    toastr.options = {
                        closeButton: true,
                        progressBar: true,
                    };

                    const type = event.detail?.type || 'info';
                    const message = event.detail?.message || '';
                    const title = event.detail?.title || '';

                    if (typeof toastr[type] === 'function') {
                        toastr[type](message, title);
                    } else {
                        toastr.info(message, title);
                    }
                });
            }

            function disposeBootstrapInstances(scope = document) {
                if (typeof bootstrap === 'undefined') return;

                scope.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
                    bootstrap.Tooltip.getInstance(el)?.dispose();
                });

                scope.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => {
                    bootstrap.Popover.getInstance(el)?.dispose();
                });

                scope.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((el) => {
                    bootstrap.Dropdown.getInstance(el)?.dispose();
                });

                scope.querySelectorAll('.offcanvas').forEach((el) => {
                    bootstrap.Offcanvas.getInstance(el)?.dispose();
                });

                scope.querySelectorAll('.modal').forEach((el) => {
                    bootstrap.Modal.getInstance(el)?.dispose();
                });
            }

            function initBootstrapPlugins(scope = document) {
                if (typeof bootstrap === 'undefined') return;

                scope.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
                    bootstrap.Tooltip.getOrCreateInstance(el);
                });

                scope.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => {
                    bootstrap.Popover.getOrCreateInstance(el);
                });

                scope.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((el) => {
                    bootstrap.Dropdown.getOrCreateInstance(el);
                });
            }

            function initFeather() {
                if (typeof feather !== 'undefined') {
                    feather.replace();
                }
            }

            function initWaves() {
                if (typeof Waves !== 'undefined') {
                    try {
                        Waves.init();
                        Waves.attach('.btn, .btn-icon, .waves-effect');
                    } catch (_) {}
                }
            }

            function initSimplebar() {
                if (typeof SimpleBar === 'undefined') return;

                document.querySelectorAll('[data-simplebar]').forEach((el) => {
                    if (!el.SimpleBar) {
                        new SimpleBar(el);
                    }
                });
            }

            function initThemeInteractions() {
                // Sidebar hamburger
                const hamburger = document.getElementById('topnav-hamburger-icon');
                if (hamburger && !hamburger.dataset.layoutBound) {
                    hamburger.dataset.layoutBound = '1';

                    hamburger.addEventListener('click', function () {
                        document.body.classList.toggle('sidebar-enable');

                        const size = document.documentElement.getAttribute('data-sidebar-size');
                        document.documentElement.setAttribute(
                            'data-sidebar-size',
                            size === 'sm' ? 'lg' : 'sm'
                        );
                    });
                }

                // Vertical overlay
                document.querySelectorAll('.vertical-overlay').forEach((overlay) => {
                    if (overlay.dataset.layoutBound) return;
                    overlay.dataset.layoutBound = '1';

                    overlay.addEventListener('click', function () {
                        document.body.classList.remove('sidebar-enable');
                    });
                });

                // Fullscreen
                document.querySelectorAll('[data-toggle="fullscreen"]').forEach((btn) => {
                    if (btn.dataset.layoutBound) return;
                    btn.dataset.layoutBound = '1';

                    btn.addEventListener('click', function () {
                        if (!document.fullscreenElement) {
                            document.documentElement.requestFullscreen?.();
                        } else {
                            document.exitFullscreen?.();
                        }
                    });
                });

                // Dark/light mode
                document.querySelectorAll('.light-dark-mode').forEach((btn) => {
                    if (btn.dataset.layoutBound) return;
                    btn.dataset.layoutBound = '1';

                    btn.addEventListener('click', function () {
                        const current = document.documentElement.getAttribute('data-bs-theme') || 'dark';
                        const next = current === 'dark' ? 'light' : 'dark';

                        document.documentElement.setAttribute('data-bs-theme', next);

                        try {
                            localStorage.setItem('data-bs-theme', next);
                        } catch (_) {}
                    });
                });
            }

            function restoreThemeState() {
                try {
                    const saved = localStorage.getItem('data-bs-theme');
                    if (saved) {
                        document.documentElement.setAttribute('data-bs-theme', saved);
                    }
                } catch (_) {}
            }

            function bootLayout(scope = document) {
                restoreThemeState();
                initBootstrapPlugins(scope);
                initFeather();
                initWaves();
                initSimplebar();
                initThemeInteractions();
            }

            document.addEventListener('DOMContentLoaded', function () {
                initToastrListener();
                bootLayout(document);
            });

            document.addEventListener('livewire:navigating', function () {
                disposeBootstrapInstances(document);
            });

            document.addEventListener('livewire:navigated', function () {
                initToastrListener();

                requestAnimationFrame(() => {
                    bootLayout(document);
                });
            });
        })();
    </script>
</body>
</html>
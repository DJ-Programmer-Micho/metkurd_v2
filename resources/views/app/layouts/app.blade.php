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
    <meta name="mobile-web-app-title" content="{{ __('METKURD.AI') }}">
    <meta name="author" content="Michel Shabo">
    <meta name="robots" content="index, follow">

    <link rel="shortcut icon" href="{{ app('logo_1024_tran_black') }}">

    <link href="{{ asset('app/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('app/css/app.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('general/css/toaster.css') }}" rel="stylesheet" type="text/css">

    <title>{{ $title ?? __('App') . ' | ' . __('MET KURD') }}</title>

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
            --glass-accent: rgba(255,255,255,.05);
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,.06);
            background:
                linear-gradient(
                    135deg,
                    var(--glass-accent) 0%,
                    rgba(255,255,255,.02) 42%,
                    rgba(18, 19, 21, .94) 100%
                ),
                rgba(20, 21, 23, .92);
            box-shadow: 0 8px 24px rgba(0,0,0,.28);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: inherit;
        }

        .glass-load--info{
            --glass-accent: rgba(0, 123, 255, .18);
            border-color: rgba(0, 123, 255, .25);
        }

        .glass-load--warning{
            --glass-accent: rgba(255, 193, 7, .2);
            border-color: rgba(255, 193, 7, .25);
        }

        .glass-load--success{
            --glass-accent: rgba(40, 167, 69, .18);
            border-color: rgba(40, 167, 69, .25);
        }

        .glass-load--danger{
            --glass-accent: rgba(220, 53, 69, .18);
            border-color: rgba(220, 53, 69, .25);
        }

        .glass-load--secondary{
            --glass-accent: rgba(108, 117, 125, .14);
            border-color: rgba(255,255,255,.08);
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

        #app-navbar-menu .navbar-nav .nav-link.menu-link.active{
            color: var(--vz-vertical-menu-item-active-color) !important;
            background-color: rgba(var(--vz-primary-rgb, 102, 145, 231), .16);
            font-weight: 600;
        }

        #app-navbar-menu .navbar-nav .nav-link.menu-link.active:hover{
            color: var(--vz-vertical-menu-item-active-color) !important;
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
    <script data-navigate-once src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

                const dispatchToast = (payload) => {
                    if (typeof toastr === 'undefined') return;

                    toastr.options = {
                        closeButton: true,
                        progressBar: true,
                    };

                    const detail = Array.isArray(payload)
                        ? (payload[0] || {})
                        : ((payload && typeof payload === 'object' && 'detail' in payload)
                            ? (payload.detail || {})
                            : (payload || {}));

                    const type = detail.type || 'info';
                    const message = detail.message || '';
                    const title = detail.title || '';

                    if (typeof toastr[type] === 'function') {
                        toastr[type](message, title);
                    } else {
                        toastr.info(message, title);
                    }
                };

                window.__APP_DISPATCH_TOAST__ = dispatchToast;

                window.addEventListener('alert', dispatchToast);

                document.addEventListener('livewire:init', () => {
                    if (window.__APP_TOASTR_LIVEWIRE_BOUND__ || typeof Livewire === 'undefined') {
                        return;
                    }

                    window.__APP_TOASTR_LIVEWIRE_BOUND__ = true;

                    Livewire.on('alert', (payload) => {
                        dispatchToast(payload);
                    });
                });
            }

            function resolveScope(scope) {
                if (scope && typeof scope.querySelectorAll === 'function') {
                    return scope;
                }

                return document;
            }

            function pageScope() {
                return document.querySelector('.page-content') || document;
            }

            function disposeBootstrapInstances(scope = document) {
                scope = resolveScope(scope);

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
                scope = resolveScope(scope);

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

            function initFeather(scope = document) {
                scope = resolveScope(scope);

                if (typeof feather !== 'undefined' && scope.querySelector('[data-feather]')) {
                    feather.replace();
                }
            }

            function initWaves(scope = document) {
                scope = resolveScope(scope);

                if (typeof Waves !== 'undefined') {
                    try {
                        if (!window.__APP_WAVES_INIT__) {
                            Waves.init();
                            window.__APP_WAVES_INIT__ = true;
                        }

                        scope.querySelectorAll('.btn, .btn-icon, .waves-effect').forEach((el) => {
                            if (el.dataset.wavesBound === '1') {
                                return;
                            }

                            Waves.attach(el);
                            el.dataset.wavesBound = '1';
                        });
                    } catch (_) {}
                }
            }

            function initSimplebar(scope = document) {
                scope = resolveScope(scope);

                if (typeof SimpleBar === 'undefined') return;

                scope.querySelectorAll('[data-simplebar]').forEach((el) => {
                    if (!el.SimpleBar) {
                        new SimpleBar(el);
                    }
                });
            }

            function syncSidebarTriggerState() {
                const hamburger = document.getElementById('topnav-hamburger-icon');
                if (!hamburger) return;

                const isMobile = window.innerWidth <= 767;
                const expanded = isMobile
                    ? document.body.classList.contains('vertical-sidebar-enable')
                    : document.documentElement.getAttribute('data-sidebar-size') !== 'sm';

                hamburger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }

            function handleHamburgerToggle(event) {
                event.preventDefault();

                const viewportWidth = document.documentElement.clientWidth;
                const layout = document.documentElement.getAttribute('data-layout');
                const hamburgerIcon = event.currentTarget?.querySelector('.hamburger-icon') ?? document.querySelector('.hamburger-icon');

                if (viewportWidth > 767) {
                    hamburgerIcon?.classList.toggle('open');
                }

                if (layout === 'horizontal') {
                    document.body.classList.toggle('menu');
                }

                if (layout === 'vertical') {
                    if (viewportWidth <= 1025 && viewportWidth > 767) {
                        document.body.classList.remove('vertical-sidebar-enable');
                        document.documentElement.setAttribute(
                            'data-sidebar-size',
                            document.documentElement.getAttribute('data-sidebar-size') === 'sm' ? '' : 'sm'
                        );
                    } else if (viewportWidth > 1025) {
                        document.body.classList.remove('vertical-sidebar-enable');
                        document.documentElement.setAttribute(
                            'data-sidebar-size',
                            document.documentElement.getAttribute('data-sidebar-size') === 'lg' ? 'sm' : 'lg'
                        );
                    } else {
                        document.body.classList.add('vertical-sidebar-enable');
                        document.documentElement.setAttribute('data-sidebar-size', 'lg');
                    }
                }

                if (layout === 'semibox') {
                    if (viewportWidth > 767) {
                        if (document.documentElement.getAttribute('data-sidebar-visibility') === 'show') {
                            document.documentElement.setAttribute(
                                'data-sidebar-size',
                                document.documentElement.getAttribute('data-sidebar-size') === 'lg' ? 'sm' : 'lg'
                            );
                        } else {
                            document.getElementById('sidebar-visibility-show')?.click();
                        }
                    } else {
                        document.body.classList.add('vertical-sidebar-enable');
                        document.documentElement.setAttribute('data-sidebar-size', 'lg');
                    }
                }

                if (layout === 'twocolumn') {
                    document.body.classList.toggle('twocolumn-panel');
                }

                syncSidebarTriggerState();
            }

            function bindHamburgerToggle() {
                const hamburger = document.getElementById('topnav-hamburger-icon');
                if (!hamburger) return;

                if (hamburger.dataset.layoutHamburgerBound === '1') {
                    syncSidebarTriggerState();
                    return;
                }

                // Replacing the node clears one-time theme listeners that are bound to stale elements after wire:navigate.
                const reboundHamburger = hamburger.cloneNode(true);
                reboundHamburger.dataset.layoutHamburgerBound = '1';
                hamburger.replaceWith(reboundHamburger);
                reboundHamburger.addEventListener('click', handleHamburgerToggle);

                syncSidebarTriggerState();
            }

            function closeVerticalSidebarFromOverlay() {
                document.body.classList.remove('vertical-sidebar-enable');

                if (sessionStorage.getItem('data-layout') === 'twocolumn') {
                    document.body.classList.add('twocolumn-panel');
                } else {
                    const savedSidebarSize = sessionStorage.getItem('data-sidebar-size');
                    if (savedSidebarSize) {
                        document.documentElement.setAttribute('data-sidebar-size', savedSidebarSize);
                    }
                }

                syncSidebarTriggerState();
            }

            function bindVerticalOverlayDismiss() {
                document.querySelectorAll('.vertical-overlay').forEach((overlay) => {
                    if (overlay.dataset.layoutOverlayBound === '1') return;
                    overlay.dataset.layoutOverlayBound = '1';
                    overlay.addEventListener('click', closeVerticalSidebarFromOverlay);
                });
            }

            function resetMobileSidebarState() {
                if (window.innerWidth > 767) {
                    syncSidebarTriggerState();
                    return;
                }

                document.body.classList.remove('sidebar-enable', 'vertical-sidebar-enable');
                document.documentElement.setAttribute('data-sidebar-size', 'lg');
                document.querySelector('.hamburger-icon')?.classList.remove('open');
                syncSidebarTriggerState();
            }

            function initThemeInteractions() {
                syncSidebarTriggerState();
                bindHamburgerToggle();
                bindVerticalOverlayDismiss();

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
                scope = resolveScope(scope);
                restoreThemeState();
                initBootstrapPlugins(scope);
                initFeather(scope);
                initWaves(scope);
                initSimplebar(scope);
                initThemeInteractions();
            }

            document.addEventListener('DOMContentLoaded', function () {
                initToastrListener();
                bootLayout(document);
            });

            document.addEventListener('livewire:navigating', function () {
                disposeBootstrapInstances(pageScope());
            });

            document.addEventListener('livewire:navigated', function () {
                initToastrListener();

                requestAnimationFrame(() => {
                    bootLayout(pageScope());
                    resetMobileSidebarState();
                });
            });

            window.addEventListener('resize', function () {
                syncSidebarTriggerState();
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('#topnav-hamburger-icon') && !event.target.closest('.vertical-overlay')) {
                    return;
                }

                requestAnimationFrame(() => {
                    syncSidebarTriggerState();
                });
            });
        })();
    </script>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-6Q34426580"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-6Q34426580');
    </script>
</body>
</html>

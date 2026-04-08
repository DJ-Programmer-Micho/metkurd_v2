@props([
    'title' => null,
    'description' => null,
    'keywords' => null,
    'canonical' => null,
])

@php
    use App\Support\LandingContent;

    $locale = app()->getLocale();
    $direction = in_array($locale, ['ar', 'ku'], true) ? 'rtl' : 'ltr';
    $siteName = LandingContent::text('site.name');
    $pageTitle = $title ? trim($title . ' | ' . $siteName) : $siteName;
    $pageDescription = $description ?: LandingContent::text('site.meta_description');
    $pageKeywords = $keywords ?: LandingContent::text('site.meta_keywords');
    $canonicalUrl = $canonical ?: url()->current();
    $favicon = app()->bound('logo_1024_tran') ? app('logo_1024_tran') : asset('favicon.ico');
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="keywords" content="{{ $pageKeywords }}">
    <meta name="theme-color" content="#07111f">
    <meta name="robots" content="index, follow">
    <meta name="author" content="{{ LandingContent::text('site.author') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->currentLocale()) }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="shortcut icon" href="{{ $favicon }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">

    <script data-navigate-once>
        (() => {
            try {
                const storedTheme = localStorage.getItem('theme');

                if (storedTheme === 'light' || storedTheme === 'dark') {
                    document.documentElement.setAttribute('data-theme', storedTheme);
                    return;
                }
            } catch (error) {
                // Ignore storage access issues and keep the default theme.
            }

            document.documentElement.setAttribute('data-theme', 'dark');
        })();
    </script>

    <link href="{{ asset('app/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    @livewireStyles
    @stack('styles')
    {{ $head ?? '' }}
</head>
<body class="{{ $direction === 'rtl' ? 'landing-rtl' : 'landing-ltr' }}">
    <livewire:landing::components.navigation />

    <main id="main-content">
        {{ $slot }}
    </main>

    <livewire:landing::components.footer />

    <script data-navigate-once src="{{ asset('app/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    @livewireScripts
    @stack('scripts')
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-YW1SEMF1D9"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-YW1SEMF1D9');
    </script>
</body>
</html>

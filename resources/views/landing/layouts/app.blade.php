@props([
    'title' => null,
    'description' => null,
    'keywords' => null,
    'canonical' => null,
    'image' => null,
])

@php
    use App\Support\LandingContent;
    use Illuminate\Support\Str;

    $locale = app()->getLocale();
    $direction = in_array($locale, ['ar', 'ku'], true) ? 'rtl' : 'ltr';
    $supportedLocales = array_keys(LandingContent::section('locales'));
    $localeMeta = [
        'en' => ['og' => 'en_US', 'hreflang' => 'en'],
        'ar' => ['og' => 'ar_IQ', 'hreflang' => 'ar-IQ'],
        'ku' => ['og' => 'ku_IQ', 'hreflang' => 'ku-IQ'],
    ];

    $siteName = LandingContent::text('site.name');
    $siteTagline = LandingContent::text('site.tagline');
    $siteAuthor = LandingContent::text('site.author');
    $siteDescription = LandingContent::text('site.meta_description');
    $siteKeywords = LandingContent::text('site.meta_keywords');
    $siteSubject = LandingContent::text('site.subject');
    $siteType = LandingContent::text('site.type', [], 'website');
    $robots = LandingContent::text('site.robots', [], 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
    $themeColor = LandingContent::text('site.theme_color', [], '#07111f');
    $defaultImageAlt = LandingContent::text('site.default_image_alt', [], $siteName);

    $sanitizeMeta = static fn (?string $value): string => trim(Str::squish(strip_tags((string) $value)));
    $absoluteAssetUrl = static function (?string $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL)
            ? $value
            : asset(ltrim($value, '/'));
    };
    $absolutePageUrl = static function (?string $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL)
            ? $value
            : url(ltrim($value, '/'));
    };

    $rawTitle = $sanitizeMeta($title);
    $defaultTitle = trim($siteName . ($siteTagline !== '' ? ' | ' . $siteTagline : ''));
    $pageTitle = $rawTitle !== ''
        ? (Str::endsWith($rawTitle, ' | ' . $siteName) || $rawTitle === $siteName ? $rawTitle : $rawTitle . ' | ' . $siteName)
        : $defaultTitle;
    $pageDescription = $sanitizeMeta($description) ?: $siteDescription;
    $pageKeywords = $sanitizeMeta($keywords) ?: $siteKeywords;
    $canonicalUrl = $absolutePageUrl($canonical) ?: url()->current();

    $favicon = app()->bound('logo_1024_tran_black')
        ? asset(app('logo_1024_tran_black'))
        : asset('favicon.ico');
    $defaultLogo = asset(app()->bound('logo_1024') ? app('logo_1024') : 'favicon.ico');
    $pageImage = $absoluteAssetUrl($image) ?: $defaultLogo;
    $pageImageAlt = $rawTitle !== '' ? $rawTitle : $defaultImageAlt;

    $authorEmail = '';
    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $siteAuthor, $matches) === 1) {
        $authorEmail = $matches[0];
    }

    $alternateUrls = [];
    $currentRoute = request()->route();
    $currentRouteName = $currentRoute?->getName();
    $routeParameters = $currentRoute?->parameters() ?? [];

    if ($currentRouteName && array_key_exists('locale', $routeParameters)) {
        foreach ($supportedLocales as $supportedLocale) {
            try {
                $alternateUrls[$supportedLocale] = route($currentRouteName, array_merge($routeParameters, [
                    'locale' => $supportedLocale,
                ]));
            } catch (\Throwable $e) {
                // Ignore routes that cannot be rebuilt for locale alternates.
            }
        }
    }

    $xDefaultLocale = config('app.locale', 'en');
    $xDefaultUrl = $alternateUrls[$xDefaultLocale] ?? ($alternateUrls['en'] ?? $canonicalUrl);

    $organizationId = url('/') . '#organization';
    $websiteId = url('/') . '#website';
    $webpageId = $canonicalUrl . '#webpage';

    $structuredData = [
        '@context' => 'https://schema.org',
        '@graph' => [
            array_filter([
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => $siteName,
                'alternateName' => $siteTagline,
                'url' => url('/'),
                'description' => $siteDescription,
                'email' => $authorEmail !== '' ? $authorEmail : null,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $defaultLogo,
                ],
                'image' => $defaultLogo,
            ], static fn ($value) => $value !== null && $value !== ''),
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'url' => url('/'),
                'name' => $siteName,
                'alternateName' => $siteTagline,
                'description' => $siteDescription,
                'inLanguage' => $localeMeta[$locale]['hreflang'] ?? $locale,
                'publisher' => [
                    '@id' => $organizationId,
                ],
            ],
            [
                '@type' => 'WebPage',
                '@id' => $webpageId,
                'url' => $canonicalUrl,
                'name' => $pageTitle,
                'description' => $pageDescription,
                'keywords' => $pageKeywords,
                'inLanguage' => $localeMeta[$locale]['hreflang'] ?? $locale,
                'isPartOf' => [
                    '@id' => $websiteId,
                ],
                'about' => $siteSubject,
                'primaryImageOfPage' => [
                    '@type' => 'ImageObject',
                    'url' => $pageImage,
                ],
            ],
        ],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="author" content="{{ $siteAuthor }}">
    <meta name="theme-color" content="{{ $themeColor }}">
    <meta name="robots" content="{{ $robots }}">
    <meta name="format-detection" content="telephone=no">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    @if(!empty($pageKeywords))
        <meta name="keywords" content="{{ $pageKeywords }}">
    @endif

    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="icon" type="image/png" href="{{ $favicon }}">
    <link rel="apple-touch-icon" href="{{ $favicon }}">

    {{-- Route-aware canonical and hreflang tags help search engines index the right localized page. --}}
    @foreach($alternateUrls as $alternateLocale => $alternateUrl)
        <link rel="alternate" hreflang="{{ $localeMeta[$alternateLocale]['hreflang'] ?? $alternateLocale }}" href="{{ $alternateUrl }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $xDefaultUrl }}">

    {{-- Open Graph keeps link previews consistent across social platforms and messaging apps. --}}
    <meta property="og:type" content="{{ $siteType }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:locale" content="{{ $localeMeta[$locale]['og'] ?? str_replace('-', '_', $locale) }}">
    @foreach($alternateUrls as $alternateLocale => $alternateUrl)
        @if($alternateLocale !== $locale)
            <meta property="og:locale:alternate" content="{{ $localeMeta[$alternateLocale]['og'] ?? str_replace('-', '_', $alternateLocale) }}">
        @endif
    @endforeach
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $pageImage }}">
    <meta property="og:image:secure_url" content="{{ $pageImage }}">
    <meta property="og:image:alt" content="{{ $pageImageAlt }}">

    {{-- Twitter/X cards reuse the same canonical metadata for cleaner sharing. --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $pageImage }}">
    <meta name="twitter:image:alt" content="{{ $pageImageAlt }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Arabic:wght@400;500;700&display=swap"
        rel="stylesheet"
    >

    {{-- JSON-LD helps search engines understand the brand, website, and current localized page. --}}
    <script type="application/ld+json">
        @json($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    </script>

    @stack('meta')

    <script data-navigate-once>
        (() => {
            try {
                const storedTheme = localStorage.getItem('theme');
                if (storedTheme === 'light' || storedTheme === 'dark') {
                    document.documentElement.setAttribute('data-theme', storedTheme);
                    return;
                }
            } catch (error) {}
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

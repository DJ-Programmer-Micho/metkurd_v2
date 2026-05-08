@props([
    'title' => null,
    'description' => null,
    'keywords' => null,
    'canonical' => null,
    'image' => null,
])

@php
    use App\Support\Landing\SiteMetaSettingsRepository;
    use App\Support\LandingContent;
    use Illuminate\Support\Str;

    $locale = app()->getLocale();
    $direction = in_array($locale, ['ar', 'ku'], true) ? 'rtl' : 'ltr';
    $supportedLocales = array_keys(LandingContent::section('locales'));
    $localeMeta = [
        'en' => ['og' => 'en_US', 'hreflang' => 'en'],
        'ar' => ['og' => 'ar_IQ', 'hreflang' => 'ar'],
        'ku' => ['og' => 'ku_IQ', 'hreflang' => 'ku'],
    ];

    $siteName = LandingContent::text('site.name');
    $siteTagline = LandingContent::text('site.tagline');
    $siteAuthor = LandingContent::text('site.author');
    $siteFounderName = LandingContent::text('site.founder_name');
    $siteCoFounderName = LandingContent::text('site.cofounder_name');
    $siteOrigin = LandingContent::text('site.origin');
    $siteDescription = LandingContent::text('site.meta_description');
    $siteKeywords = LandingContent::text('site.meta_keywords');
    $siteSubject = LandingContent::text('site.subject');
    $siteType = LandingContent::text('site.type', [], 'website');
    $robots = LandingContent::text('site.robots', [], 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1');
    $themeColor = LandingContent::text('site.theme_color', [], '#07111f');
    $defaultImageAlt = LandingContent::text('site.default_image_alt', [], $siteName);
    $metaSettings = app(SiteMetaSettingsRepository::class);

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

    $settingsDefaultMetaTitle = $sanitizeMeta($metaSettings->defaultMetaTitle());
    $settingsDefaultMetaDescription = $sanitizeMeta($metaSettings->defaultMetaDescription());
    $settingsDefaultOgTitle = $sanitizeMeta($metaSettings->defaultOgTitle());
    $settingsDefaultOgDescription = $sanitizeMeta($metaSettings->defaultOgDescription());
    $settingsDefaultTwitterTitle = $sanitizeMeta($metaSettings->defaultTwitterTitle());
    $settingsDefaultTwitterDescription = $sanitizeMeta($metaSettings->defaultTwitterDescription());

    $rawTitle = $sanitizeMeta($title);
    $rawDescription = $sanitizeMeta($description);
    $defaultTitle = $settingsDefaultMetaTitle !== ''
        ? $settingsDefaultMetaTitle
        : trim($siteName . ($siteTagline !== '' ? ' | ' . $siteTagline : ''));
    $pageTitle = $rawTitle !== ''
        ? (Str::endsWith($rawTitle, ' | ' . $siteName) || $rawTitle === $siteName || Str::contains($rawTitle, $siteName) ? $rawTitle : $rawTitle . ' | ' . $siteName)
        : $defaultTitle;
    $pageDescription = $rawDescription ?: ($settingsDefaultMetaDescription !== '' ? $settingsDefaultMetaDescription : $siteDescription);
    $pageKeywords = $sanitizeMeta($keywords) ?: $siteKeywords;
    $canonicalUrl = $absolutePageUrl($canonical) ?: url()->current();

    $fallbackFavicon = app()->bound('logo_1024_tran_black')
        ? asset(app('logo_1024_tran_black'))
        : (app()->bound('logo_1024') ? asset(app('logo_1024')) : asset('favicon.ico'));
    $favicon = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->faviconPath())) ?: $fallbackFavicon;
    $appleTouchIcon = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->appleTouchIconPath())) ?: $favicon;
    $appIcon192 = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->appIcon192Path())) ?: null;
    $appIcon512 = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->appIcon512Path())) ?: null;
    $defaultLogo = $appIcon512
        ?: (app()->bound('logo_1024') ? asset(app('logo_1024')) : $fallbackFavicon);
    $defaultOgImage = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->ogImagePath())) ?: $defaultLogo;
    $defaultTwitterImage = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->twitterImagePath())) ?: $defaultOgImage;
    $pageImage = $absoluteAssetUrl($image) ?: $defaultOgImage;
    $twitterImage = $absoluteAssetUrl($image) ?: $defaultTwitterImage;
    $pageImageAlt = $rawTitle !== '' ? $rawTitle : $defaultImageAlt;
    $ogTitle = $rawTitle !== '' ? $pageTitle : ($settingsDefaultOgTitle !== '' ? $settingsDefaultOgTitle : $pageTitle);
    $ogDescription = $rawDescription !== '' ? $pageDescription : ($settingsDefaultOgDescription !== '' ? $settingsDefaultOgDescription : $pageDescription);
    $twitterTitle = $rawTitle !== '' ? $pageTitle : ($settingsDefaultTwitterTitle !== '' ? $settingsDefaultTwitterTitle : $ogTitle);
    $twitterDescription = $rawDescription !== '' ? $pageDescription : ($settingsDefaultTwitterDescription !== '' ? $settingsDefaultTwitterDescription : $ogDescription);

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
    $organizationLogo = $appIcon512 ?: $defaultLogo;

    $structuredData = [
        '@context' => 'https://schema.org',
        '@graph' => [
            array_filter([
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => $siteName,
                'alternateName' => $siteTagline,
                'slogan' => $siteTagline,
                'url' => url('/'),
                'description' => $siteDescription,
                'email' => $authorEmail !== '' ? $authorEmail : null,
                'founder' => array_values(array_filter([
                    $siteFounderName !== '' ? [
                        '@type' => 'Person',
                        'name' => $siteFounderName,
                    ] : null,
                    $siteCoFounderName !== '' ? [
                        '@type' => 'Person',
                        'name' => $siteCoFounderName,
                    ] : null,
                ])),
                'foundingLocation' => $siteOrigin !== '' ? [
                    '@type' => 'Place',
                    'name' => $siteOrigin,
                ] : null,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $organizationLogo,
                ],
                'image' => $organizationLogo,
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
    <link rel="icon" href="{{ $favicon }}">
    @if($appIcon192)
        <link rel="icon" type="image/png" sizes="192x192" href="{{ $appIcon192 }}">
    @endif
    @if($appIcon512)
        <link rel="icon" type="image/png" sizes="512x512" href="{{ $appIcon512 }}">
    @endif
    <link rel="apple-touch-icon" href="{{ $appleTouchIcon }}">

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
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $pageImage }}">
    <meta property="og:image:secure_url" content="{{ $pageImage }}">
    <meta property="og:image:alt" content="{{ $pageImageAlt }}">

    {{-- Twitter/X cards reuse the same canonical metadata for cleaner sharing. --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $twitterTitle }}">
    <meta name="twitter:description" content="{{ $twitterDescription }}">
    <meta name="twitter:image" content="{{ $twitterImage }}">
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
<body>

    <div class="container mt-4">
            <a
                class="navbar-brand d-flex align-items-center gap-2 text-decoration-none"
                href="{{ route('landing.home', ['locale' => $locale]) }}"
                aria-label="{{ LandingContent::text('nav.home_label') }}"
                wire:navigate
            >
                <span class="brand-badge">
                    <img
                        class="brand-logo brand-logo--dark"
                        src="{{ asset(app('logo_1024_tran_black')) }}"
                        alt="{{ __('MetKurd AI') }}"
                    >
                    <img
                        class="brand-logo brand-logo--light"
                        src="{{ asset(app('logo_1024_tran')) }}"
                        alt="{{ __('MetKurd AI') }}"
                    >
                </span>
                <span>{{ LandingContent::text('site.name') }}</span>
            </a>
        @yield('law')
    </div>

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

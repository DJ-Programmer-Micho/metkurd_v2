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
    use App\Support\Landing\PublicSiteUrl;
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

    $sanitizeMeta = static fn (?string $value): string => trim(Str::squish(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $absoluteAssetUrl = static function (?string $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return PublicSiteUrl::asset($value);
    };
    $absolutePageUrl = static function (?string $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return PublicSiteUrl::page($value);
    };
    $assetWithVersion = static function (string $path): string {
        $trimmed = ltrim(trim($path), '/');
        $url = asset($trimmed);
        $fullPath = public_path($trimmed);

        if (! is_file($fullPath)) {
            return $url;
        }

        $version = @filemtime($fullPath);
        if (! is_int($version) || $version <= 0) {
            return $url;
        }

        return $url . '?v=' . $version;
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
    $canonicalUrl = $absolutePageUrl($canonical) ?: PublicSiteUrl::page(request()->getPathInfo());

    $defaultFaviconIco = $assetWithVersion('favicon.ico');
    $defaultFaviconSvg = $assetWithVersion('favicon.svg');
    $defaultAppleTouchIcon = $assetWithVersion('apple-touch-icon.png');
    $configuredFavicon = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->faviconPath()));
    $configuredAppleTouchIcon = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->appleTouchIconPath()));
    $favicon = $configuredFavicon ?: $defaultFaviconIco;
    $faviconSvg = $defaultFaviconSvg;
    $appleTouchIcon = $configuredAppleTouchIcon ?: $defaultAppleTouchIcon;
    $appIcon192 = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->appIcon192Path())) ?: null;
    $appIcon512 = $absoluteAssetUrl($metaSettings->publicUrl($metaSettings->appIcon512Path())) ?: null;
    $defaultLogo = $appIcon512
        ?: (app()->bound('logo_1024') ? PublicSiteUrl::asset(app('logo_1024')) : PublicSiteUrl::asset($defaultFaviconIco));
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
                $alternateUrls[$supportedLocale] = PublicSiteUrl::route($currentRouteName, array_merge($routeParameters, [
                    'locale' => $supportedLocale,
                ]));
            } catch (\Throwable $e) {
                // Ignore routes that cannot be rebuilt for locale alternates.
            }
        }
    }

    $xDefaultLocale = 'en';
    $xDefaultUrl = $alternateUrls[$xDefaultLocale] ?? ($alternateUrls['en'] ?? $canonicalUrl);

    $organizationId = PublicSiteUrl::ORIGIN . '#organization';
    $websiteId = PublicSiteUrl::ORIGIN . '#website';
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
                'url' => PublicSiteUrl::ORIGIN,
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
                'url' => PublicSiteUrl::ORIGIN,
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
    <link rel="icon" href="{{ $favicon }}" sizes="any">
    <link rel="shortcut icon" href="{{ $favicon }}">
    @if(!$configuredFavicon)
        <link rel="icon" type="image/svg+xml" href="{{ $defaultFaviconSvg }}">
    @endif
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

    {{-- JSON-LD helps search engines understand the brand, website, and current localized page. --}}
    <script type="application/ld+json">
        @json($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT)
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
                        src="{{ asset('landing/images/white_logo-44.webp') }}"
                        srcset="{{ asset('landing/images/white_logo-44.webp') }} 44w, {{ asset('landing/images/white_logo-88.webp') }} 88w"
                        sizes="22px" width="44" height="40"
                        alt="{{ __('MetKurd AI') }}"
                    >
                    <img
                        class="brand-logo brand-logo--light"
                        src="{{ asset('landing/images/black_logo-44.webp') }}"
                        srcset="{{ asset('landing/images/black_logo-44.webp') }} 44w, {{ asset('landing/images/black_logo-88.webp') }} 88w"
                        sizes="22px" width="44" height="40"
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

<?php

namespace App\Support\Landing;

use App\Models\LandingToolPage;
use App\Support\LandingContent;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LandingToolPageCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForLocale(string $locale): array
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return $this->fallbackCatalog($locale);
        }

        $definedSlugs = LandingToolPage::query()
            ->pluck('slug')
            ->map(fn ($slug) => $this->normalizeSlug((string) $slug))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $dynamicPages = LandingToolPage::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get()
            ->map(fn (LandingToolPage $page) => $this->mapModel($page, $locale))
            ->values();

        $fallbackPages = collect($this->fallbackCatalog($locale))
            ->reject(fn (array $item) => in_array($this->normalizeSlug((string) ($item['slug'] ?? '')), $definedSlugs, true))
            ->values();

        return $dynamicPages
            ->concat($fallbackPages)
            ->unique(fn (array $item) => $this->normalizeSlug((string) ($item['slug'] ?? '')))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForLocaleBySlug(string $slug, string $locale): ?array
    {
        $normalized = Str::of($slug)->lower()->replace('_', '-')->toString();

        if (Schema::hasTable('landing_tool_pages')) {
            $page = LandingToolPage::query()
                ->where('slug', $normalized)
                ->first();

            if ($page) {
                if (! $page->is_active) {
                    return null;
                }

                return $this->mapModel($page, $locale);
            }
        }

        return $this->fallbackToolBySlug($normalized, $locale);
    }

    public function importFallbackDefaults(): int
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return 0;
        }

        $locales = ['en', 'ar', 'ku'];
        $created = 0;

        foreach ($this->fallbackToolCodes() as $index => $toolCode) {
            $slug = $this->canonicalFallbackSlug($toolCode);

            if ($slug === '') {
                continue;
            }

            $exists = LandingToolPage::query()->where('slug', $slug)->exists();
            if ($exists) {
                continue;
            }

            $content = [];

            foreach ($locales as $locale) {
                $content[$locale] = $this->localizedFallbackContent($toolCode, $locale);
            }

            LandingToolPage::query()->create([
                'slug' => $slug,
                'icon_class' => null,
                'is_active' => true,
                'sort_order' => $index,
                'content' => $content,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapModel(LandingToolPage $page, string $locale): array
    {
        $content = is_array($page->content) ? $page->content : [];
        $field = fn (string $name, mixed $fallback = '') => $this->localizedField($content, $locale, $name, $fallback);
        $appDownload = $this->localizedAppDownloadFromContent($content, $locale);

        $featureCards = $this->localizedFeatureCardsFromContent($content, $locale, 'bi bi-stars');
        $featureBullets = collect($featureCards)
            ->map(fn (array $feature) => trim((string) data_get($feature, 'title', '')))
            ->filter()
            ->values()
            ->all();

        return [
            'source' => 'db',
            'slug' => $this->normalizeSlug((string) $page->slug),
            'icon' => 'bi bi-grid-1x2',
            'badge' => (string) $field('badge', ''),
            'title' => (string) $field('title', strtoupper((string) $page->slug)),
            'hero_text' => (string) $field('hero_text', ''),
            'summary' => (string) $field('summary', ''),
            'about_title' => (string) $field('about_title', ''),
            'about_copy' => (string) $field('about_copy', ''),
            'use_cases_title' => (string) $field('use_cases_title', ''),
            'use_cases' => $this->normalizeStringList($field('use_cases', [])),
            'feature_bullets' => $featureBullets,
            'feature_cards' => $featureCards,
            'meta_title' => (string) $field('meta_title', ''),
            'meta_description' => (string) $field('meta_description', ''),
            'capabilities' => $this->normalizeStringList($field('capabilities', [])),
            'app_download' => $appDownload,
            'square_image_url' => $this->publicAssetUrl($page->square_image_path),
            'hero_image_url' => $this->publicAssetUrl($page->hero_image_path),
            'card_image_url' => $this->publicAssetUrl($page->card_image_path),
            'is_active' => (bool) $page->is_active,
            'sort_order' => (int) $page->sort_order,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackCatalog(string $locale): array
    {
        $items = [];

        foreach ($this->fallbackToolCodes() as $toolCode) {
            $tool = $this->fallbackToolBySlug($this->canonicalFallbackSlug($toolCode), $locale);

            if ($tool) {
                $items[] = $tool;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fallbackToolBySlug(string $slug, string $locale): ?array
    {
        $toolCode = $this->resolveFallbackToolCode($slug);

        if ($toolCode === null) {
            return null;
        }

        $toolCatalog = $this->localizedFallbackCatalog($toolCode, $locale);
        $toolPage = $this->localizedFallbackPage($toolCode, $locale);

        if ($toolCatalog === [] || $toolPage === []) {
            return null;
        }

        $featureCards = (array) data_get($toolPage, 'features', []);
        $appDownload = $this->localizedAppDownloadFromFallback($toolPage, $locale);
        $featureBullets = collect($featureCards)
            ->map(function ($feature) {
                $title = trim((string) data_get($feature, 'title', ''));
                $copy = trim((string) data_get($feature, 'copy', ''));

                if ($title === '' && $copy === '') {
                    return null;
                }

                return trim($title . ($copy !== '' ? ' - ' . $copy : ''));
            })
            ->filter()
            ->values()
            ->all();

        return [
            'source' => 'fallback',
            'slug' => $this->canonicalFallbackSlug($toolCode),
            'icon' => (string) data_get($toolCatalog, 'icon', 'bi bi-grid-1x2'),
            'badge' => (string) data_get($toolPage, 'badge', data_get($toolCatalog, 'title', strtoupper($toolCode))),
            'title' => (string) data_get($toolPage, 'title', data_get($toolCatalog, 'title', strtoupper($toolCode))),
            'hero_text' => (string) data_get($toolPage, 'lead', ''),
            'summary' => (string) data_get($toolCatalog, 'summary', ''),
            'about_title' => (string) data_get($toolPage, 'about_title', ''),
            'about_copy' => (string) data_get($toolPage, 'about_copy', ''),
            'use_cases_title' => (string) data_get($toolPage, 'use_cases_title', ''),
            'use_cases' => $this->normalizeStringList((array) data_get($toolPage, 'use_cases', [])),
            'feature_bullets' => $featureBullets,
            'feature_cards' => $featureCards,
            'meta_title' => (string) data_get($toolPage, 'meta_title', data_get($toolCatalog, 'title', '')),
            'meta_description' => (string) data_get($toolPage, 'meta_description', ''),
            'capabilities' => $this->normalizeStringList((array) data_get($toolCatalog, 'capabilities', [])),
            'app_download' => $appDownload,
            'square_image_url' => null,
            'hero_image_url' => null,
            'card_image_url' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    protected function resolveFallbackToolCode(string $slug): ?string
    {
        return match ($this->normalizeSlug($slug)) {
            'tts' => 'tts',
            'ctts', 'clone-tts', 'clone-xtts' => 'clone_tts',
            'asr', 'wasr', 'qasr' => 'asr',
            'ocr' => 'ocr',
            'stem' => 'stem',
            default => null,
        };
    }

    /**
     * @return string[]
     */
    protected function fallbackToolCodes(): array
    {
        return ['tts', 'clone_tts', 'asr', 'ocr', 'stem'];
    }

    protected function canonicalFallbackSlug(string $toolCode): string
    {
        $fallbackSlug = $this->normalizeSlug((string) data_get(
            LandingContent::rawSection("tool_catalog.{$toolCode}"),
            'slug',
            ''
        ));

        if ($fallbackSlug !== '') {
            return $fallbackSlug;
        }

        return match ($toolCode) {
            'clone_tts' => 'ctts',
            default => $this->normalizeSlug($toolCode),
        };
    }

    protected function normalizeSlug(string $slug): string
    {
        return Str::of($slug)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->toString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function localizedFallbackContent(string $toolCode, string $locale): array
    {
        $catalog = $this->localizedFallbackCatalog($toolCode, $locale);
        $page = $this->localizedFallbackPage($toolCode, $locale);

        $features = collect((array) data_get($page, 'features', []))
            ->map(function ($feature) {
                if (! is_array($feature)) {
                    return null;
                }

                $title = trim((string) data_get($feature, 'title', ''));
                $copy = trim((string) data_get($feature, 'copy', ''));

                if ($title === '' && $copy === '') {
                    return null;
                }

                return [
                    'icon' => trim((string) data_get($feature, 'icon', 'bi bi-stars')) ?: 'bi bi-stars',
                    'title' => $title,
                    'copy' => $copy,
                ];
            })
            ->filter()
            ->values()
            ->map(fn (array $feature, int $index) => array_merge($feature, ['sort_order' => $index]))
            ->all();

        $featureBullets = collect($features)
            ->map(fn (array $feature) => trim((string) data_get($feature, 'title', '')))
            ->filter()
            ->values()
            ->all();

        return [
            'badge' => (string) data_get($page, 'badge', data_get($catalog, 'title', strtoupper($toolCode))),
            'title' => (string) data_get($page, 'title', data_get($catalog, 'title', strtoupper($toolCode))),
            'hero_text' => (string) data_get($page, 'lead', ''),
            'summary' => (string) data_get($catalog, 'summary', ''),
            'about_title' => (string) data_get($page, 'about_title', ''),
            'about_copy' => (string) data_get($page, 'about_copy', ''),
            'use_cases_title' => (string) data_get($page, 'use_cases_title', ''),
            'use_cases' => $this->normalizeStringList((array) data_get($page, 'use_cases', [])),
            'features' => $features,
            'feature_bullets' => $featureBullets,
            'meta_title' => (string) data_get($page, 'meta_title', data_get($catalog, 'title', strtoupper($toolCode))),
            'meta_description' => (string) data_get($page, 'meta_description', ''),
            'capabilities' => $this->normalizeStringList((array) data_get($catalog, 'capabilities', [])),
            'app_download' => [
                'enabled' => false,
                'title' => '',
                'body' => '',
                'ios' => [
                    'enabled' => false,
                    'url' => '',
                    'label' => '',
                ],
                'android' => [
                    'enabled' => false,
                    'url' => '',
                    'label' => '',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function localizedFallbackCatalog(string $toolCode, string $locale): array
    {
        $previousLocale = App::currentLocale();
        App::setLocale($locale);

        try {
            return LandingContent::section("tool_catalog.{$toolCode}");
        } finally {
            App::setLocale($previousLocale);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function localizedFallbackPage(string $toolCode, string $locale): array
    {
        $previousLocale = App::currentLocale();
        App::setLocale($locale);

        try {
            return LandingContent::section("tool_pages.{$toolCode}");
        } finally {
            App::setLocale($previousLocale);
        }
    }

    protected function localizedField(array $content, string $locale, string $field, mixed $fallback = ''): mixed
    {
        $value = data_get($content, "{$locale}.{$field}");

        if ($this->isEmptyLocalizedValue($value)) {
            $value = data_get($content, "en.{$field}");
        }

        if ($this->isEmptyLocalizedValue($value)) {
            return $fallback;
        }

        return $value;
    }

    protected function isEmptyLocalizedValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param  mixed  $value
     * @return string[]
     */
    protected function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $value),
            fn (string $item) => $item !== ''
        ));
    }

    protected function publicAssetUrl(?string $path): ?string
    {
        return $this->mediaStorage()->publicUrl($path);
    }

    protected function mediaStorage(): LandingMediaStorage
    {
        return app(LandingMediaStorage::class);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    protected function localizedAppDownloadFromContent(array $content, string $locale): array
    {
        $section = data_get($content, "{$locale}.app_download");
        if (! is_array($section) || $section === []) {
            $section = data_get($content, 'en.app_download', []);
        }
        if (! is_array($section)) {
            $section = [];
        }

        $enabled = $this->toBool(data_get($section, 'enabled', false));
        $title = trim((string) data_get($section, 'title', ''));
        $body = trim((string) data_get($section, 'body', ''));

        if ($title === '' && $locale !== 'en') {
            $title = trim((string) data_get($content, 'en.app_download.title', ''));
        }
        if ($body === '' && $locale !== 'en') {
            $body = trim((string) data_get($content, 'en.app_download.body', ''));
        }

        $iosEnabled = $enabled && $this->toBool(data_get($section, 'ios.enabled', false));
        $iosUrl = trim((string) data_get($section, 'ios.url', ''));
        $iosLabel = trim((string) data_get($section, 'ios.label', ''));
        if ($iosLabel === '' && $locale !== 'en') {
            $iosLabel = trim((string) data_get($content, 'en.app_download.ios.label', ''));
        }

        $androidEnabled = $enabled && $this->toBool(data_get($section, 'android.enabled', false));
        $androidUrl = trim((string) data_get($section, 'android.url', ''));
        $androidLabel = trim((string) data_get($section, 'android.label', ''));
        if ($androidLabel === '' && $locale !== 'en') {
            $androidLabel = trim((string) data_get($content, 'en.app_download.android.label', ''));
        }

        return [
            'enabled' => $enabled,
            'title' => $title,
            'body' => $body,
            'ios' => [
                'enabled' => $iosEnabled,
                'url' => $iosUrl,
                'label' => $iosLabel,
            ],
            'android' => [
                'enabled' => $androidEnabled,
                'url' => $androidUrl,
                'label' => $androidLabel,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $toolPage
     * @return array<string, mixed>
     */
    protected function localizedAppDownloadFromFallback(array $toolPage, string $locale): array
    {
        $section = data_get($toolPage, 'app_download', []);
        if (! is_array($section)) {
            $section = [];
        }

        $title = trim((string) data_get($section, 'title', ''));
        $body = trim((string) data_get($section, 'body', ''));
        $iosLabel = trim((string) data_get($section, 'ios.label', ''));
        $androidLabel = trim((string) data_get($section, 'android.label', ''));

        $localizedTitle = data_get($toolPage, "app_download.{$locale}.title");
        $localizedBody = data_get($toolPage, "app_download.{$locale}.body");
        $localizedIosLabel = data_get($toolPage, "app_download.{$locale}.ios_label");
        $localizedAndroidLabel = data_get($toolPage, "app_download.{$locale}.android_label");

        if (is_string($localizedTitle) && trim($localizedTitle) !== '') {
            $title = trim($localizedTitle);
        }
        if (is_string($localizedBody) && trim($localizedBody) !== '') {
            $body = trim($localizedBody);
        }
        if (is_string($localizedIosLabel) && trim($localizedIosLabel) !== '') {
            $iosLabel = trim($localizedIosLabel);
        }
        if (is_string($localizedAndroidLabel) && trim($localizedAndroidLabel) !== '') {
            $androidLabel = trim($localizedAndroidLabel);
        }

        return [
            'enabled' => $this->toBool(data_get($section, 'enabled', false)),
            'title' => $title,
            'body' => $body,
            'ios' => [
                'enabled' => $this->toBool(data_get($section, 'ios.enabled', false)),
                'url' => trim((string) data_get($section, 'ios.url', '')),
                'label' => $iosLabel,
            ],
            'android' => [
                'enabled' => $this->toBool(data_get($section, 'android.enabled', false)),
                'url' => trim((string) data_get($section, 'android.url', '')),
                'label' => $androidLabel,
            ],
        ];
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, array{icon:string,title:string,copy:string,sort_order:int}>
     */
    protected function localizedFeatureCardsFromContent(array $content, string $locale, string $defaultIcon): array
    {
        $value = data_get($content, "{$locale}.features");

        if (! is_array($value) || $value === []) {
            $value = data_get($content, "en.features", []);
        }

        $cards = [];

        if (is_array($value) && $value !== []) {
            foreach ($value as $index => $feature) {
                if (is_string($feature)) {
                    $title = trim($feature);
                    if ($title === '') {
                        continue;
                    }

                    $cards[] = [
                        'icon' => $defaultIcon,
                        'title' => $title,
                        'copy' => '',
                        'sort_order' => $index,
                    ];
                    continue;
                }

                if (! is_array($feature)) {
                    continue;
                }

                $title = trim((string) data_get($feature, 'title', ''));
                $copy = trim((string) data_get($feature, 'copy', ''));

                if ($title === '' && $copy === '') {
                    continue;
                }

                $cards[] = [
                    'icon' => trim((string) data_get($feature, 'icon', $defaultIcon)) ?: $defaultIcon,
                    'title' => $title,
                    'copy' => $copy,
                    'sort_order' => (int) data_get($feature, 'sort_order', $index),
                ];
            }
        }

        if ($cards === []) {
            $legacyBullets = data_get($content, "{$locale}.feature_bullets");
            if (! is_array($legacyBullets) || $legacyBullets === []) {
                $legacyBullets = data_get($content, 'en.feature_bullets', []);
            }

            foreach ($this->normalizeStringList($legacyBullets) as $index => $bullet) {
                $cards[] = [
                    'icon' => $defaultIcon,
                    'title' => $bullet,
                    'copy' => '',
                    'sort_order' => $index,
                ];
            }
        }

        usort($cards, fn (array $a, array $b) => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return array_values($cards);
    }
}

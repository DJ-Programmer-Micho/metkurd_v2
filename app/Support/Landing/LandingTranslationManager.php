<?php

namespace App\Support\Landing;

use App\Support\AreaJsonTranslations;
use App\Support\LandingContent;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LandingTranslationManager
{
    /**
     * @var string[]
     */
    protected array $locales = ['en', 'ar', 'ku'];

    /**
     * @return string[]
     */
    public function locales(): array
    {
        return $this->locales;
    }

    /**
     * @return array<string, array{key:string,section:string,default:string,en:string,ar:string,ku:string}>
     */
    public function editableEntries(): array
    {
        $catalog = LandingContent::translationCatalog();
        $files = $this->readAllLocaleFiles();

        $entries = [];

        foreach ($catalog as $key => $default) {
            $entries[$key] = [
                'key' => $key,
                'section' => $this->sectionForKey($key),
                'default' => $default,
                'en' => $this->resolveLocaleValue('en', $key, $default, $files),
                'ar' => $this->resolveLocaleValue('ar', $key, $default, $files),
                'ku' => $this->resolveLocaleValue('ku', $key, $default, $files),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, array<string, string|null>>  $rows
     */
    public function saveEditableEntries(array $rows): void
    {
        $catalog = LandingContent::translationCatalog();
        $files = $this->readAllLocaleFiles();
        $managedKeys = array_values(array_unique(array_merge(
            array_keys($catalog),
            $this->dottedKeyUnion($files)
        )));

        $normalizedRows = [];

        foreach ($managedKeys as $key) {
            $normalizedRows[$key] = [
                'en' => $this->normalizeInput($rows[$key]['en'] ?? null),
                'ar' => $this->normalizeInput($rows[$key]['ar'] ?? null),
                'ku' => $this->normalizeInput($rows[$key]['ku'] ?? null),
            ];
        }

        $enDefaults = [];
        foreach ($managedKeys as $key) {
            $enDefaults[$key] = $normalizedRows[$key]['en']
                ?: ($files['en'][$key] ?? ($catalog[$key] ?? ''));
        }

        foreach ($this->locales as $locale) {
            $existing = $files[$locale];
            $next = $existing;

            foreach ($managedKeys as $key) {
                $explicit = $normalizedRows[$key][$locale];

                if ($explicit !== '') {
                    $next[$key] = $explicit;

                    continue;
                }

                if ($locale === 'en') {
                    $next[$key] = $enDefaults[$key];

                    continue;
                }

                if (isset($existing[$key]) && is_string($existing[$key]) && trim($existing[$key]) !== '') {
                    $next[$key] = trim($existing[$key]);

                    continue;
                }

                $next[$key] = $enDefaults[$key];
            }

            // Keep deterministic output and valid UTF-8 JSON for safe deployments and diff reviews.
            ksort($next, SORT_NATURAL);
            $this->writeLocaleFile($locale, $next);
            AreaJsonTranslations::flush('landing', $locale);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function readAllLocaleFiles(): array
    {
        $files = [];

        foreach ($this->locales as $locale) {
            $files[$locale] = $this->readLocaleFile($locale);
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    protected function readLocaleFile(string $locale): array
    {
        $path = $this->localeFilePath($locale);

        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $clean = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key) || is_array($value)) {
                continue;
            }

            $clean[$key] = (string) $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $translations
     */
    protected function writeLocaleFile(string $locale, array $translations): void
    {
        $path = $this->localeFilePath($locale);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $encoded = json_encode(
            $translations,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (! is_string($encoded)) {
            throw new RuntimeException("Unable to encode landing {$locale} translations to JSON.");
        }

        $encoded .= PHP_EOL;

        $existingRaw = is_file($path) ? file_get_contents($path) : '';
        $this->writeBackup($locale, is_string($existingRaw) ? $existingRaw : '');

        $tmp = tempnam($directory, "landing-{$locale}-");
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temporary file for {$locale} translations.");
        }

        try {
            if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
                throw new RuntimeException("Unable to write temporary {$locale} translations file.");
            }

            if (! @rename($tmp, $path)) {
                if (! @copy($tmp, $path)) {
                    throw new RuntimeException("Unable to replace {$locale} translations file.");
                }
                @unlink($tmp);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    protected function writeBackup(string $locale, string $content): void
    {
        if ($content === '') {
            return;
        }

        $backupDir = storage_path('app/landing-lang-backups');

        if (! is_dir($backupDir)) {
            File::makeDirectory($backupDir, 0755, true, true);
        }

        $backupPath = $backupDir.DIRECTORY_SEPARATOR.$locale.'-'.now()->format('Ymd-His').'.json';
        @file_put_contents($backupPath, $content, LOCK_EX);
    }

    protected function localeFilePath(string $locale): string
    {
        return resource_path("lang/landing/{$locale}.json");
    }

    /**
     * @param  array<string, array<string, string>>  $files
     */
    protected function resolveLocaleValue(string $locale, string $key, string $default, array $files): string
    {
        $localeValues = $files[$locale] ?? [];

        if (array_key_exists($key, $localeValues)) {
            return (string) $localeValues[$key];
        }

        // Bridge from legacy JSON files where the literal English text was used as the key.
        if ($default !== '' && array_key_exists($default, $localeValues)) {
            return (string) $localeValues[$default];
        }

        return $locale === 'en' ? $default : ($files['en'][$key] ?? $default);
    }

    /**
     * @param  array<string, array<string, string>>  $files
     * @return string[]
     */
    protected function dottedKeyUnion(array $files): array
    {
        $union = [];

        foreach ($files as $localeValues) {
            foreach ($localeValues as $key => $value) {
                if (! str_contains((string) $key, '.')) {
                    continue;
                }

                $union[] = (string) $key;
            }
        }

        return array_values(array_unique($union));
    }

    protected function normalizeInput(?string $value): string
    {
        return trim((string) $value);
    }

    protected function sectionForKey(string $key): string
    {
        $normalized = strtolower(trim($key));

        if ($normalized === '') {
            return 'general_brand';
        }

        if (
            str_contains($normalized, '.faq_heading.')
            || str_contains($normalized, '.faqs.')
        ) {
            return 'faq';
        }

        if (
            str_ends_with($normalized, '.primary_cta')
            || str_ends_with($normalized, '.secondary_cta')
            || str_starts_with($normalized, 'plans.') && str_ends_with($normalized, '.cta')
            || str_starts_with($normalized, 'common.')
            || in_array($normalized, ['nav.get_started', 'nav.sign_in'], true)
        ) {
            return 'buttons_cta';
        }

        if (
            str_contains($normalized, '.meta.')
            || str_contains($normalized, '_meta_')
            || str_starts_with($normalized, 'site.meta_')
            || in_array($normalized, ['site.robots', 'site.theme_color', 'site.type'], true)
        ) {
            return 'seo_metadata';
        }

        if (
            str_starts_with($normalized, 'pricing_page.offer_')
            || str_starts_with($normalized, 'site.subject')
            || str_starts_with($normalized, 'site.author')
            || str_starts_with($normalized, 'site.founder_')
            || str_starts_with($normalized, 'site.cofounder_')
            || str_starts_with($normalized, 'site.origin')
        ) {
            return 'schema_content';
        }

        if (
            in_array($normalized, [
                'nav.home',
                'nav.tools',
                'nav.pricing',
                'nav.contact',
                'nav.overview',
                'nav.research_development',
                'nav.kurdish_ai_challenges',
                'nav.how_built',
                'nav.privacy',
                'nav.terms',
            ], true)
        ) {
            return 'breadcrumbs';
        }

        if (
            str_starts_with($normalized, 'tool_pages.clone_tts.features.2.')
            || str_starts_with($normalized, 'terms_page.cards.1.')
        ) {
            return 'voice_cloning_safety';
        }

        if (str_starts_with($normalized, 'research_development_page.')) {
            return 'research_development';
        }

        if (str_starts_with($normalized, 'kurdish_ai_challenges_page.')) {
            return 'kurdish_ai_challenges';
        }

        if (str_starts_with($normalized, 'how_built_page.')) {
            return 'how_built';
        }

        if (str_starts_with($normalized, 'overview_page.')) {
            return 'overview';
        }

        if (str_starts_with($normalized, 'home.')) {
            return 'homepage';
        }

        if (str_starts_with($normalized, 'pricing_page.') || str_starts_with($normalized, 'plans.')) {
            return 'pricing';
        }

        if (str_starts_with($normalized, 'tools_page.')
            || str_starts_with($normalized, 'tool_catalog.')
            || str_starts_with($normalized, 'tool_pages.')
            || str_starts_with($normalized, 'tool_detail.')
        ) {
            return 'tools';
        }

        if (
            str_starts_with($normalized, 'privacy_page.')
            || str_starts_with($normalized, 'terms_page.')
            || str_starts_with($normalized, 'footer.status_')
        ) {
            return 'trust_privacy';
        }

        if (str_starts_with($normalized, 'contact_page.')) {
            return 'contact';
        }

        if (
            str_starts_with($normalized, 'site.')
            || str_starts_with($normalized, 'locales.')
            || str_starts_with($normalized, 'nav.')
            || str_starts_with($normalized, 'footer.')
            || str_starts_with($normalized, 'google_review.')
        ) {
            return 'general_brand';
        }

        return 'general_brand';
    }
}

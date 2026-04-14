<?php

namespace App\Support\Landing;

use App\Models\SiteMetaSetting;
use App\Support\LandingContent;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class SiteMetaSettingsRepository
{
    protected const STORAGE_DIR = 'site-meta';
    /** @var array<string, mixed>|null */
    protected ?array $settingsCache = null;
    protected ?bool $hasTableCache = null;

    public function defaultMetaTitle(): string
    {
        $fallback = trim(LandingContent::text('site.name') . ' | ' . LandingContent::text('site.tagline'));

        return $this->getString('meta.default_title', $fallback);
    }

    public function defaultMetaDescription(): string
    {
        return $this->getString('meta.default_description', LandingContent::text('site.meta_description'));
    }

    public function defaultOgTitle(): string
    {
        return $this->getString('meta.default_og_title', $this->defaultMetaTitle());
    }

    public function defaultOgDescription(): string
    {
        return $this->getString('meta.default_og_description', $this->defaultMetaDescription());
    }

    public function defaultTwitterTitle(): string
    {
        return $this->getString('meta.default_twitter_title', $this->defaultOgTitle());
    }

    public function defaultTwitterDescription(): string
    {
        return $this->getString('meta.default_twitter_description', $this->defaultOgDescription());
    }

    public function faviconPath(): ?string
    {
        return $this->getPath('meta.favicon_path');
    }

    public function appIcon192Path(): ?string
    {
        return $this->getPath('meta.app_icon_192_path');
    }

    public function appIcon512Path(): ?string
    {
        return $this->getPath('meta.app_icon_512_path');
    }

    public function appleTouchIconPath(): ?string
    {
        return $this->getPath('meta.apple_touch_icon_path');
    }

    public function ogImagePath(): ?string
    {
        return $this->getPath('meta.og_image_path');
    }

    public function twitterImagePath(): ?string
    {
        return $this->getPath('meta.twitter_image_path');
    }

    /**
     * @return array<string, string|null>
     */
    public function adminSettings(): array
    {
        return [
            'default_meta_title' => $this->defaultMetaTitle(),
            'default_meta_description' => $this->defaultMetaDescription(),
            'default_og_title' => $this->defaultOgTitle(),
            'default_og_description' => $this->defaultOgDescription(),
            'default_twitter_title' => $this->defaultTwitterTitle(),
            'default_twitter_description' => $this->defaultTwitterDescription(),
            'favicon_path' => $this->faviconPath(),
            'app_icon_192_path' => $this->appIcon192Path(),
            'app_icon_512_path' => $this->appIcon512Path(),
            'apple_touch_icon_path' => $this->appleTouchIconPath(),
            'og_image_path' => $this->ogImagePath(),
            'twitter_image_path' => $this->twitterImagePath(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload): void
    {
        if (! $this->hasTable()) {
            return;
        }

        $this->setString('meta.default_title', (string) ($payload['default_meta_title'] ?? ''));
        $this->setString('meta.default_description', (string) ($payload['default_meta_description'] ?? ''));
        $this->setString('meta.default_og_title', (string) ($payload['default_og_title'] ?? ''));
        $this->setString('meta.default_og_description', (string) ($payload['default_og_description'] ?? ''));
        $this->setString('meta.default_twitter_title', (string) ($payload['default_twitter_title'] ?? ''));
        $this->setString('meta.default_twitter_description', (string) ($payload['default_twitter_description'] ?? ''));

        $this->storeAssetIfPresent('meta.favicon_path', $payload['favicon_upload'] ?? null, 'favicon');
        $this->storeAssetIfPresent('meta.app_icon_192_path', $payload['app_icon_192_upload'] ?? null, 'app-icon-192');
        $this->storeAssetIfPresent('meta.app_icon_512_path', $payload['app_icon_512_upload'] ?? null, 'app-icon-512');
        $this->storeAssetIfPresent('meta.apple_touch_icon_path', $payload['apple_touch_icon_upload'] ?? null, 'apple-touch-icon');
        $this->storeAssetIfPresent('meta.og_image_path', $payload['og_image_upload'] ?? null, 'og-image');
        $this->storeAssetIfPresent('meta.twitter_image_path', $payload['twitter_image_upload'] ?? null, 'twitter-image');
    }

    public function publicUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    protected function hasTable(): bool
    {
        if ($this->hasTableCache === null) {
            $this->hasTableCache = Schema::hasTable('site_meta_settings');
        }

        return $this->hasTableCache;
    }

    protected function getString(string $key, string $fallback = ''): string
    {
        if (! $this->hasTable()) {
            return trim($fallback);
        }

        $value = $this->loadSettings()[$key] ?? null;

        if (is_array($value) && array_key_exists('value', $value)) {
            return trim((string) $value['value']) ?: trim($fallback);
        }

        if (is_string($value)) {
            return trim($value) ?: trim($fallback);
        }

        return trim($fallback);
    }

    protected function getPath(string $key): ?string
    {
        $value = $this->getString($key, '');

        return $value !== '' ? $value : null;
    }

    protected function setString(string $key, string $value): void
    {
        if (! $this->hasTable()) {
            return;
        }

        $payload = ['value' => trim($value)];

        SiteMetaSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $payload]
        );

        $cache = $this->loadSettings();
        $cache[$key] = $payload;
        $this->settingsCache = $cache;
    }

    protected function storeAssetIfPresent(string $key, mixed $uploadedFile, string $basename): void
    {
        if (! $this->hasTable() || ! is_object($uploadedFile) || ! method_exists($uploadedFile, 'storeAs')) {
            return;
        }

        $currentPath = $this->getPath($key);
        $extension = strtolower((string) (method_exists($uploadedFile, 'getClientOriginalExtension')
            ? $uploadedFile->getClientOriginalExtension()
            : 'png'));
        $extension = $extension !== '' ? $extension : 'png';

        $filename = $basename . '-' . now()->format('YmdHis') . '.' . $extension;
        $storedPath = $uploadedFile->storeAs(self::STORAGE_DIR, $filename, 'public');

        if (! is_string($storedPath) || trim($storedPath) === '') {
            return;
        }

        if ($currentPath && ! str_starts_with($currentPath, 'http://') && ! str_starts_with($currentPath, 'https://')) {
            Storage::disk('public')->delete($currentPath);
        }

        $this->setString($key, $storedPath);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadSettings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        if (! $this->hasTable()) {
            $this->settingsCache = [];
            return $this->settingsCache;
        }

        $this->settingsCache = SiteMetaSetting::query()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (SiteMetaSetting $setting) => [$setting->key => $setting->value])
            ->all();

        return $this->settingsCache;
    }
}

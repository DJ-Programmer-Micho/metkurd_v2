<?php

namespace App\Support\Landing;

use App\Models\SiteMetaSetting;
use App\Support\LandingContent;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SiteMetaSettingsRepository
{
    protected const STORAGE_DIR = 'web-setting/site-meta';
    protected const PATH_VERSION_KEYS = [
        'meta.favicon_path' => 'meta.favicon_updated_at',
        'meta.app_icon_192_path' => 'meta.app_icon_192_updated_at',
        'meta.app_icon_512_path' => 'meta.app_icon_512_updated_at',
        'meta.apple_touch_icon_path' => 'meta.apple_touch_icon_updated_at',
        'meta.og_image_path' => 'meta.og_image_updated_at',
        'meta.twitter_image_path' => 'meta.twitter_image_updated_at',
    ];
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
        $normalized = $this->mediaStorage()->normalizeStoredPath($path);

        if (! is_string($normalized) || trim($normalized) === '') {
            return null;
        }

        if (! Str::startsWith($normalized, ['http://', 'https://']) && ! $this->mediaStorage()->exists($normalized)) {
            return null;
        }

        $url = $this->mediaStorage()->publicUrl($normalized);

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        if (Str::startsWith($normalized, ['http://', 'https://'])) {
            return $url;
        }

        $lastModified = $this->mediaStorage()->lastModified($normalized);
        if (! is_int($lastModified) || $lastModified <= 0) {
            $lastModified = $this->assetUpdatedTimestamp($normalized);
        }

        if (! is_int($lastModified) || $lastModified <= 0) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . $lastModified;
    }

    /**
     * @return array{mime_type: string|null, width: int|null, height: int|null}|null
     */
    public function imageMetadata(?string $path): ?array
    {
        $normalized = $this->mediaStorage()->normalizeStoredPath($path);

        if (! is_string($normalized) || trim($normalized) === '' || Str::startsWith($normalized, ['http://', 'https://'])) {
            return null;
        }

        if (! $this->mediaStorage()->exists($normalized)) {
            return null;
        }

        $mimeType = $this->mediaStorage()->mimeType($normalized);
        $stream = $this->mediaStorage()->readStream($normalized);

        if (! is_resource($stream)) {
            return [
                'mime_type' => $mimeType,
                'width' => null,
                'height' => null,
            ];
        }

        try {
            $binary = stream_get_contents($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! is_string($binary) || $binary === '') {
            return [
                'mime_type' => $mimeType,
                'width' => null,
                'height' => null,
            ];
        }

        $imageSize = @getimagesizefromstring($binary);
        $resolvedMime = is_array($imageSize) && is_string($imageSize['mime'] ?? null)
            ? trim((string) $imageSize['mime'])
            : $mimeType;

        return [
            'mime_type' => $resolvedMime !== '' ? $resolvedMime : null,
            'width' => is_array($imageSize) && isset($imageSize[0]) ? (int) $imageSize[0] : null,
            'height' => is_array($imageSize) && isset($imageSize[1]) ? (int) $imageSize[1] : null,
        ];
    }

    public function refreshRuntimeCache(): void
    {
        $this->settingsCache = null;
        $this->hasTableCache = null;
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

        $currentPath = $this->mediaStorage()->normalizeStoredPath($this->getPath($key));
        $extension = strtolower((string) (method_exists($uploadedFile, 'getClientOriginalExtension')
            ? $uploadedFile->getClientOriginalExtension()
            : 'png'));
        $extension = $extension !== '' ? $extension : 'png';

        $filename = $basename
            . '-'
            . now()->format('YmdHisv')
            . '-'
            . Str::lower(Str::random(6))
            . '.'
            . $extension;
        $storedPath = $uploadedFile->storeAs(self::STORAGE_DIR, $filename, $this->mediaStorage()->diskName());

        if (! is_string($storedPath) || trim($storedPath) === '') {
            return;
        }

        $this->mediaStorage()->delete($currentPath);

        $this->setString($key, $storedPath);
        $this->setAssetUpdatedTimestamp($key);
    }

    protected function mediaStorage(): LandingMediaStorage
    {
        return app(LandingMediaStorage::class);
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

    protected function setAssetUpdatedTimestamp(string $pathKey): void
    {
        $versionKey = self::PATH_VERSION_KEYS[$pathKey] ?? null;

        if (! is_string($versionKey) || trim($versionKey) === '') {
            return;
        }

        $this->setString($versionKey, (string) now()->getTimestamp());
    }

    protected function assetUpdatedTimestamp(string $normalizedPath): ?int
    {
        foreach (self::PATH_VERSION_KEYS as $pathKey => $versionKey) {
            $storedPath = $this->mediaStorage()->normalizeStoredPath($this->getPath($pathKey));

            if (! is_string($storedPath) || $storedPath !== $normalizedPath) {
                continue;
            }

            $storedVersion = trim($this->getString($versionKey, ''));
            $timestamp = is_numeric($storedVersion) ? (int) $storedVersion : 0;

            return $timestamp > 0 ? $timestamp : null;
        }

        return null;
    }
}

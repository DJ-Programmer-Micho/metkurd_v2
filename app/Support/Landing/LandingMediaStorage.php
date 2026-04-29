<?php

namespace App\Support\Landing;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LandingMediaStorage
{
    public function diskName(): string
    {
        $configured = trim((string) config('landing.media_disk', ''));

        if ($configured !== '') {
            return $configured;
        }

        return 'public';
    }

    public function publicUrl(?string $path): ?string
    {
        $resolved = $this->normalizeStoredPath($path);

        if ($resolved === null) {
            return null;
        }

        if ($this->isAbsoluteUrl($resolved)) {
            return $resolved;
        }

        $normalizedPath = $resolved;

        if (! $this->isAllowedPublicPath($normalizedPath)) {
            return null;
        }

        if ($this->usesProxyUrls()) {
            return $this->proxyUrl($normalizedPath);
        }

        return $this->absoluteUrl(Storage::disk($this->diskName())->url($normalizedPath));
    }

    public function normalizeStoredPath(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if ($this->isAbsoluteUrl($path)) {
            $localStoragePath = $this->extractAppStorageRelativePath($path);
            if ($localStoragePath !== null) {
                return $localStoragePath;
            }

            $diskPath = $this->extractDiskStorageRelativePath($path);
            if ($diskPath !== null) {
                return $diskPath;
            }

            return $path;
        }

        return $this->normalizeRelativePath($path);
    }

    public function delete(?string $path): void
    {
        $normalizedPath = $this->normalizeStoredPath($path);

        if ($normalizedPath === null) {
            return;
        }

        if ($this->isAbsoluteUrl($normalizedPath)) {
            return;
        }

        Storage::disk($this->diskName())->delete($normalizedPath);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->diskName())->exists($path);
    }

    public function readStream(string $path)
    {
        return Storage::disk($this->diskName())->readStream($path);
    }

    public function mimeType(string $path): ?string
    {
        try {
            $mime = Storage::disk($this->diskName())->mimeType($path);
            return is_string($mime) && trim($mime) !== '' ? $mime : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function size(string $path): ?int
    {
        try {
            $size = Storage::disk($this->diskName())->size($path);
            return is_numeric($size) ? (int) $size : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function lastModified(string $path): ?int
    {
        try {
            $modified = Storage::disk($this->diskName())->lastModified($path);
            return is_numeric($modified) ? (int) $modified : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function maxAge(): int
    {
        $maxAge = (int) config('landing.media_proxy_max_age', 3600);

        return $maxAge > 0 ? $maxAge : 3600;
    }

    public function isAllowedPublicPath(string $path): bool
    {
        $normalized = $this->normalizeRelativePath($path);

        if ($normalized === null) {
            return false;
        }

        $prefixes = Arr::where(
            (array) config('landing.public_media_prefixes', []),
            fn ($prefix) => is_string($prefix) && trim($prefix) !== ''
        );

        if ($prefixes === []) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix);
            $prefix = trim($prefix, '/');

            if ($prefix === '') {
                continue;
            }

            if (Str::startsWith($normalized, $prefix . '/')) {
                return true;
            }

            if ($normalized === $prefix) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeRelativePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'storage/')) {
            $path = (string) Str::after($path, 'storage/');
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path);

        if ($path === '' || Str::contains($path, ['../', '..\\'])) {
            return null;
        }

        return $path !== '' ? $path : null;
    }

    protected function extractAppStorageRelativePath(string $url): ?string
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');

        if ($appUrl !== '' && Str::startsWith($url, $appUrl . '/storage/')) {
            return $this->normalizeRelativePath((string) Str::after($url, $appUrl . '/storage/'));
        }

        $urlHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($urlHost !== null && $appHost !== null && $urlHost === $appHost && Str::startsWith($path, '/storage/')) {
            return $this->normalizeRelativePath((string) Str::after($path, '/storage/'));
        }

        return null;
    }

    protected function extractDiskStorageRelativePath(string $url): ?string
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        $urlPath = (string) parse_url($url, PHP_URL_PATH);

        if (! is_string($urlHost) || trim($urlHost) === '' || trim($urlPath) === '') {
            return null;
        }

        $diskConfig = (array) config('filesystems.disks.' . $this->diskName(), []);
        $bucket = trim((string) ($diskConfig['bucket'] ?? ''));

        $baseCandidates = array_values(array_filter([
            (string) ($diskConfig['url'] ?? ''),
            (string) ($diskConfig['endpoint'] ?? ''),
            (string) config('app.cloudfront', ''),
            (string) config('services.cloudfront.url', ''),
            app()->bound('cloudfront') ? (string) app('cloudfront') : '',
        ], fn (string $value) => trim($value) !== ''));

        $normalizedUrlPath = ltrim($urlPath, '/');

        foreach ($baseCandidates as $baseUrl) {
            $baseHost = parse_url($baseUrl, PHP_URL_HOST);
            if (! is_string($baseHost) || $baseHost !== $urlHost) {
                continue;
            }

            $basePath = trim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

            if ($basePath !== '' && Str::startsWith($normalizedUrlPath, $basePath . '/')) {
                return $this->normalizeRelativePath((string) Str::after($normalizedUrlPath, $basePath . '/'));
            }

            if ($bucket !== '' && Str::startsWith($normalizedUrlPath, $bucket . '/')) {
                return $this->normalizeRelativePath((string) Str::after($normalizedUrlPath, $bucket . '/'));
            }
        }

        return null;
    }

    protected function isAbsoluteUrl(string $path): bool
    {
        return Str::startsWith($path, ['http://', 'https://']);
    }

    protected function usesProxyUrls(): bool
    {
        return strtolower(trim((string) config('landing.media_url_strategy', 'proxy'))) !== 'direct';
    }

    protected function proxyUrl(string $path): string
    {
        $encoded = collect(explode('/', ltrim($path, '/')))
            ->map(fn (string $segment) => rawurlencode($segment))
            ->implode('/');

        return url('media/web/' . $encoded);
    }

    protected function absoluteUrl(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return $path;
        }

        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        if (Str::startsWith($path, '//')) {
            $scheme = (string) parse_url((string) config('app.url', ''), PHP_URL_SCHEME);
            $scheme = $scheme !== '' ? $scheme : 'https';

            return $scheme . ':' . $path;
        }

        return url(ltrim($path, '/'));
    }
}

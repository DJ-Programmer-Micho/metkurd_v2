<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;

class AreaJsonTranslations
{
    protected static array $cache = [];

    public static function get(string $key, ?string $area = null, ?string $locale = null): ?string
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        $translations = static::translations($area, $locale);

        if (! array_key_exists($key, $translations)) {
            return null;
        }

        $value = $translations[$key];

        if (is_array($value)) {
            return null;
        }

        return (string) $value;
    }

    public static function all(?string $area = null, ?string $locale = null): array
    {
        return static::translations($area, $locale);
    }

    public static function flush(?string $area = null, ?string $locale = null): void
    {
        if ($area === null && $locale === null) {
            static::$cache = [];

            return;
        }

        if ($area !== null && $locale !== null) {
            unset(static::$cache["{$area}:{$locale}"]);

            return;
        }

        foreach (array_keys(static::$cache) as $cacheKey) {
            [$cachedArea, $cachedLocale] = array_pad(explode(':', $cacheKey, 2), 2, null);

            if ($area !== null && $cachedArea !== $area) {
                continue;
            }

            if ($locale !== null && $cachedLocale !== $locale) {
                continue;
            }

            unset(static::$cache[$cacheKey]);
        }
    }

    public static function group(string $prefix, ?string $area = null, ?string $locale = null): array
    {
        $prefix = trim($prefix);

        if ($prefix === '') {
            return [];
        }

        $translations = static::translations($area, $locale);
        $group = [];
        $needle = $prefix.'.';

        foreach ($translations as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, $needle)) {
                continue;
            }

            $relativeKey = substr($key, strlen($needle));

            if ($relativeKey === '' || is_array($value)) {
                continue;
            }

            Arr::set($group, $relativeKey, is_string($value) ? $value : (string) $value);
        }

        return static::normalize($group);
    }

    protected static function translations(?string $area = null, ?string $locale = null): array
    {
        $area = $area ?: static::detectArea();
        $locale = $locale ?: App::currentLocale();
        $cacheKey = "{$area}:{$locale}";

        if (array_key_exists($cacheKey, static::$cache)) {
            return static::$cache[$cacheKey];
        }

        $path = resource_path("lang/{$area}/{$locale}.json");

        if (! is_file($path)) {
            return static::$cache[$cacheKey] = [];
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (! is_array($decoded)) {
            return static::$cache[$cacheKey] = [];
        }

        return static::$cache[$cacheKey] = $decoded;
    }

    protected static function detectArea(): string
    {
        $path = request()?->getPathInfo() ?? '/';

        if (preg_match('#^/(en|ar|ku)/super-admin(?:/|$)#', $path)) {
            return 'admin';
        }

        if (preg_match('#^/(en|ar|ku)/app(?:/|$)#', $path)) {
            return 'app';
        }

        return 'landing';
    }

    protected static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = static::normalize($item);
        }

        if ($value === []) {
            return [];
        }

        $keys = array_keys($value);
        $numericKeys = array_filter($keys, fn ($key) => is_int($key) || ctype_digit((string) $key));

        if (count($numericKeys) !== count($keys)) {
            return $value;
        }

        ksort($value, SORT_NUMERIC);

        return array_values($value);
    }
}

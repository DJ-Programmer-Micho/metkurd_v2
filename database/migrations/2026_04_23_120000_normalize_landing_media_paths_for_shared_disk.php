<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeLandingToolPagePaths();
        $this->normalizeSiteMetaPaths();
    }

    public function down(): void
    {
        // Intentionally left empty: normalization is safe/idempotent and not meaningfully reversible.
    }

    protected function normalizeLandingToolPagePaths(): void
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return;
        }

        DB::table('landing_tool_pages')
            ->select(['id', 'square_image_path', 'hero_image_path', 'card_image_path'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach (['square_image_path', 'hero_image_path', 'card_image_path'] as $column) {
                        $original = $row->{$column};
                        $normalized = $this->normalizePath($original);
                        $originalNormalized = $this->normalizeNullableScalar($original);

                        if ($normalized !== $originalNormalized) {
                            $updates[$column] = $normalized;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    $updates['updated_at'] = now();

                    DB::table('landing_tool_pages')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            }, 'id');
    }

    protected function normalizeSiteMetaPaths(): void
    {
        if (! Schema::hasTable('site_meta_settings')) {
            return;
        }

        $keys = [
            'meta.favicon_path',
            'meta.app_icon_192_path',
            'meta.app_icon_512_path',
            'meta.apple_touch_icon_path',
            'meta.og_image_path',
            'meta.twitter_image_path',
        ];

        DB::table('site_meta_settings')
            ->select(['id', 'key', 'value'])
            ->whereIn('key', $keys)
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $decoded = is_array($row->value)
                        ? $row->value
                        : json_decode((string) $row->value, true);

                    if (! is_array($decoded) || ! array_key_exists('value', $decoded)) {
                        continue;
                    }

                    $original = $this->normalizeNullableScalar($decoded['value'] ?? null);
                    $normalized = $this->normalizePath($decoded['value'] ?? null);

                    if ($normalized === $original) {
                        continue;
                    }

                    $decoded['value'] = $normalized ?? '';

                    DB::table('site_meta_settings')
                        ->where('id', $row->id)
                        ->update([
                            'value' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }

    protected function normalizePath(mixed $path): ?string
    {
        $path = $this->normalizeNullableScalar($path);

        if ($path === null) {
            return null;
        }

        if ($this->isAbsoluteUrl($path)) {
            $relativeAppStoragePath = $this->extractAppStorageRelativePath($path);

            if ($relativeAppStoragePath === null) {
                return $path;
            }

            $path = $relativeAppStoragePath;
        }

        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'storage/')) {
            $path = (string) Str::after($path, 'storage/');
        }

        $path = trim($path);

        return $path !== '' ? $path : null;
    }

    protected function normalizeNullableScalar(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function isAbsoluteUrl(string $path): bool
    {
        return Str::startsWith($path, ['http://', 'https://']);
    }

    protected function extractAppStorageRelativePath(string $url): ?string
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');

        if ($appUrl !== '' && Str::startsWith($url, $appUrl.'/storage/')) {
            return trim((string) Str::after($url, $appUrl.'/storage/'));
        }

        $urlHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($urlHost !== null && $appHost !== null && $urlHost === $appHost && Str::startsWith($path, '/storage/')) {
            return trim((string) Str::after($path, '/storage/'));
        }

        return null;
    }
};

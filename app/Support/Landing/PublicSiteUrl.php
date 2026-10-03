<?php

namespace App\Support\Landing;

/** Public discovery identity only; never changes application navigation or signed URLs. */
final class PublicSiteUrl
{
    public const ORIGIN = 'https://metkurd.ai';

    public static function page(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return self::ORIGIN.'/'.ltrim($path, '/');
    }

    public static function route(string $name, array $parameters = []): string
    {
        return self::page(route($name, $parameters, false));
    }

    public static function asset(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        // Preserve deliberately configured external media/CDN URLs.
        if ($host && ! in_array($host, ['metkurd.ai', 'www.metkurd.ai', request()->getHost(), parse_url(config('app.url'), PHP_URL_HOST)], true)) {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return self::page($url).($query ? '?'.$query : '');
    }
}

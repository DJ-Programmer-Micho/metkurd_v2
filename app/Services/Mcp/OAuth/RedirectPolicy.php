<?php

namespace App\Services\Mcp\OAuth;

final class RedirectPolicy
{
    public static function allows(string $uri, string $type = 'web'): bool
    {
        $parts = parse_url($uri);
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if (! $parts || filter_var($uri, FILTER_VALIDATE_URL) === false
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $uri) || str_contains($uri, '\\') || str_contains($uri, '*')) {
            return false;
        }
        if ($type === 'native' && ($parts['scheme'] ?? '') === 'http') {
            return in_array($parts['host'], ['127.0.0.1', '[::1]', 'localhost'], true);
        }

        return ($parts['scheme'] ?? '') === 'https' && str_contains($host, '.')
            && ! str_ends_with($host, '.localhost') && ! str_ends_with($host, '.local')
            && ! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);
    }

    public static function matches(string $uri, array $registered, string $type): bool
    {
        if (! self::allows($uri, $type)) {
            return false;
        }
        foreach ($registered as $candidate) {
            if (! is_string($candidate) || ! self::allows($candidate, $type)) {
                continue;
            }
            if ($candidate === $uri) {
                return true;
            }
            // RFC 8252 port relaxation applies to IP literals, not localhost names.
            if ($type === 'native' && str_starts_with($uri, 'http://') && parse_url($uri, PHP_URL_HOST) !== 'localhost') {
                $actual = parse_url($uri);
                $expected = parse_url($candidate);
                unset($actual['port'], $expected['port']);
                if ($actual === $expected) {
                    return true;
                }
            }
        }

        return false;
    }
}

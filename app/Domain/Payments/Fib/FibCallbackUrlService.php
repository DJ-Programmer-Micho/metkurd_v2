<?php

namespace App\Domain\Payments\Fib;

use Illuminate\Support\Str;

class FibCallbackUrlService
{
    public function absoluteRoute(string $routeName): string
    {
        $baseUrl = rtrim((string) config('fib.callback_base_url', config('app.url')), '/');
        $path = route($routeName, absolute: false);

        return $baseUrl . $path;
    }

    public function ensurePublicUrl(string $url, string $channel = 'payment'): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($host === '') {
            throw new \RuntimeException('The FIB callback URL could not be generated. Configure APP_URL or FIB_CALLBACK_BASE_URL.');
        }

        if ($channel === 'subscription' && $scheme !== 'https') {
            throw new \RuntimeException('FIB subscription callbacks must use a public HTTPS URL. Configure FIB_CALLBACK_BASE_URL accordingly.');
        }

        $normalizedHost = strtolower(trim($host));

        if (in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true)
            || Str::endsWith($normalizedHost, '.local')
        ) {
            throw new \RuntimeException('FIB callbacks cannot use localhost. Configure FIB_CALLBACK_BASE_URL to a public URL.');
        }
    }
}

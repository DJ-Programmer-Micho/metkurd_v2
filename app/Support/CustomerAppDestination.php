<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Customer navigation only. Authentication, entitlements and billing stay at their boundaries. */
final class CustomerAppDestination
{
    public static function locale(?string $locale = null): string
    {
        $locale ??= request()->route('locale') ?? (request()->hasSession() ? session('applocale') : null) ?? app()->getLocale();

        return in_array($locale, ['en', 'ar', 'ku'], true) ? $locale : 'en';
    }

    public static function home(?string $locale = null): string
    {
        return route(self::homeRoute(), ['locale' => self::locale($locale)]);
    }

    public static function homeRoute(): string
    {
        return config('metkurd_v2.enabled') ? 'app.v2.home'
            : (config('customer_app.v1_enabled') ? 'app.home' : 'landing.home');
    }

    public static function afterAuthentication(): string
    {
        return self::intended(session()->pull('url.intended')) ?? self::home();
    }

    public static function intended(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\\\\\x00-\x20\x7f]/', $url) || str_starts_with($url, '//')) {
            return null;
        }
        $parts = parse_url($url);
        $trusted = parse_url(config('app.url'));
        if (! $parts || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $port = fn ($p) => $p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80);
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || strtolower($parts['host'] ?? '') !== strtolower($trusted['host'] ?? '')
                || ($parts['scheme'] ?? '') !== ($trusted['scheme'] ?? '') || $port($parts) !== $port($trusted)) {
                return null;
            }
        }
        $path = $parts['path'] ?? '';
        if (! str_starts_with($path, '/') || str_contains($path, '%') || str_contains($path, '//')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)) {
            return null;
        }
        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return null;
        }
        $middleware = $route->gatherMiddleware();
        if (! in_array('auth:app', $middleware, true)
            || in_array($route->getName(), ['app.email.otp', 'app.phone.otp', 'app.suspended', 'app.password.email'], true)) {
            return null;
        }
        if (in_array('app.v1.enabled', $middleware, true) && ! config('customer_app.v1_enabled')) {
            return self::legacy($route->getName(), $route->parameters());
        }
        if (in_array('app.v2.enabled', $middleware, true) && ! config('metkurd_v2.enabled')) {
            return self::home($route->parameter('locale'));
        }

        return $url;
    }

    public static function legacy(?string $name, array $parameters = []): string
    {
        $locale = self::locale($parameters['locale'] ?? null);
        if (! config('metkurd_v2.enabled')) {
            return self::home($locale);
        }
        $map = [
            'app.profile' => 'app.v2.profile', 'app.storage' => 'app.v2.storage',
            'app.billing' => 'app.v2.billing', 'app.api-access' => 'app.v2.api',
            'subscription-plan' => 'app.v2.subscription-plans', 'storage-plan' => 'app.v2.storage-plans',
            'addon-credits' => 'app.v2.addon-credits', 'payments.fib.show' => 'app.v2.payments.fib.show',
            'app.caption' => 'app.v2.caption', 'app.ocr' => 'app.v2.ocr',
        ];
        if (isset($map[$name])) {
            return route($map[$name], array_intersect_key($parameters, array_flip(['payment'])) + ['locale' => $locale]);
        }
        $tools = [
            'app.xomni' => ['service' => 'text-to-speech', 'tool' => 'apollo-1'],
            'app.clone-xomni' => ['service' => 'clone-text-to-speech', 'tool' => 'vector-1'],
        ];
        if (isset($tools[$name])) {
            return route('app.v2.tool', $tools[$name] + ['locale' => $locale]);
        }
        if ($name === 'app.stem') {
            return route('app.v2.service', ['service' => 'stem', 'locale' => $locale]);
        }

        return self::home($locale);
    }
}

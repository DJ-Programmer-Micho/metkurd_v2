<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

class LocalizationMainMiddleware
{
    public array $selectedLanguages = ['en', 'ar', 'ku'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*')) {
            return $next($request);
        }

        // 1) Resolve locale (route param > session > app.default)
        $sessionLocale = $request->hasSession()
            ? $request->session()->get('applocale', config('app.locale'))
            : config('app.locale');

        $locale = $request->route('locale') ?? $sessionLocale;
        if (! in_array($locale, $this->selectedLanguages, true)) {
            $locale = config('app.locale');
        }
        App::setLocale($locale);

        if ($request->hasSession()) {
            $request->session()->put('applocale', $locale);
        }

        // 2) Select area and add JSON paths
        $area = $this->detectArea($request);
        // dd($area);
        // Optional: keep top-level resources/lang/{locale}.json as "common"
        // Lang::addJsonPath(resource_path('lang'));
        // Area-specific JSON (resources/lang/{area}/{locale}.json)
        Lang::addJsonPath(resource_path("lang/{$area}"));

        return $next($request);
    }

    /**
     * POST /set-locale – called from your form
     */
    public function setLocale(Request $request)
    {
        $selected = $request->string('locale')->toString();
        $sessionLocale = $request->hasSession()
            ? $request->session()->get('applocale', config('app.locale'))
            : config('app.locale');

        if (in_array($selected, $this->selectedLanguages, true)) {
            if ($request->hasSession()) {
                $request->session()->put('applocale', $selected);
            }
            App::setLocale($selected);
        } else {
            $selected = $sessionLocale;
        }

        $prev = $request->headers->get('referer') ?: url()->current();

        return redirect()->to($this->replaceLocaleInUrl($prev, $selected));
    }

    private function detectArea(Request $request): string
    {
        // Admin URLs look like: /{locale}/super-admin/...
        if (preg_match('#^/(en|ar|ku)/super-admin(?:/|$)#', $request->getPathInfo())) {
            return 'admin';
        }

        // App areas share the same customer-facing translation catalogue. V2 keeps
        // its own routes, but it must not fall through to the landing catalogue.
        if (preg_match('#^/(en|ar|ku)/app(?:-v2)?(?:/|$)#', $request->getPathInfo())) {
            return 'app';
        }

        // Everything else (home, marketing, etc.)
        return 'landing';
    }

    private function replaceLocaleInUrl(string $url, string $newLocale): string
    {
        $parts = parse_url($url) ?: [];
        $path = $parts['path'] ?? '/';
        $path = ltrim($path, '/');

        // If path starts with a locale, replace it; else, prepend it
        if (preg_match('#^(en|ar|ku)(/.*|$)#', $path)) {
            $path = preg_replace('#^(en|ar|ku)#', $newLocale, $path, 1);
        } else {
            $path = $newLocale.'/'.$path;
        }
        $path = '/'.trim($path, '/');

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $host = ($parts['host'] ?? request()->getHost());
        $scheme = ($parts['scheme'] ?? request()->getScheme());
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }
}

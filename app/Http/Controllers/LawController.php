<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LawController extends Controller
{
    public function termsCondition(Request $request): RedirectResponse
    {
        return $this->canonical($request, 'landing.terms');
    }

    public function privacyPolicy(Request $request): RedirectResponse
    {
        return $this->canonical($request, 'landing.privacy');
    }

    private function canonical(Request $request, string $route): RedirectResponse
    {
        $locale = $request->session()->get('applocale', config('app.locale', 'en'));
        if (! in_array($locale, ['en', 'ar', 'ku'], true)) {
            $locale = 'en';
        }

        // Session-dependent locale selection must not be cached as a permanent redirect.
        return redirect()->route($route, ['locale' => $locale])->header('Cache-Control', 'private, no-store');
    }
}

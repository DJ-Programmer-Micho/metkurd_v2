<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        if (! $request->routeIs('admin.*') && $request->hasSession()) {
            $request->session()->put('applocale', \App\Support\CustomerAppDestination::locale());
        }

        return route($request->routeIs('admin.*') ? 'admin.signin' : 'app.signin');
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureUserAppIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('app')->user();

        if ($user && (int) $user->status === 0) {
            // Allow access to suspended page and logout to avoid loops
            if (
                $request->routeIs('app.suspended') ||
                $request->routeIs('app.logout')
            ) {
                return $next($request);
            }

            return redirect()->route('app.suspended', ['locale' => 'en']); // 302 is fine
        }

        return $next($request);
    }
}

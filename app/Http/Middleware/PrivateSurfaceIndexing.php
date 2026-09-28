<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PrivateSurfaceIndexing
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->routeIs('app.*', 'admin.*') || $request->is('api', 'api/*', 'mcp', 'mcp/*', 'oauth/*',
            '.well-known/oauth-*', 'livewire/*', 'payments/*', '*/app', '*/app/*', '*/app-v2', '*/app-v2/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}

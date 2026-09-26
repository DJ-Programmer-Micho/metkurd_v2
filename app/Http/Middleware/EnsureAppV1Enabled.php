<?php

namespace App\Http\Middleware;

use App\Support\CustomerAppDestination;
use Closure;
use Illuminate\Http\Request;

class EnsureAppV1Enabled
{
    public function handle(Request $request, Closure $next)
    {
        if (config('customer_app.v1_enabled')) {
            return $next($request);
        }
        // A stale Livewire page must not execute a legacy action after disabling V1.
        abort_unless(($request->isMethod('GET') || $request->isMethod('HEAD')) && ! $request->hasHeader('X-Livewire'), 404);

        return redirect()->to(CustomerAppDestination::legacy($request->route()?->getName(), $request->route()?->parameters() ?? []));
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureV2DashboardEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('metkurd_v2.enabled'), 404);

        return $next($request);
    }
}

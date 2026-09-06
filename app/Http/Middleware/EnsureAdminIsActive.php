<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next)
    {
        AdminAccess::authorize('admin.read');

        return $next($request);
    }
}

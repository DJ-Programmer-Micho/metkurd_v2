<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCustomerVerificationIsComplete
{
    public function handle(Request $request, Closure $next)
    {
        if (
            $request->routeIs('app.email.otp')
            || $request->routeIs('app.phone.otp')
            || $request->routeIs('app.logout')
            || $request->routeIs('app.suspended')
        ) {
            return $next($request);
        }

        $customer = Auth::guard('app')->user();

        if (! $customer) {
            return redirect()->route('app.signin');
        }

        $nextVerificationRoute = $customer->nextVerificationRouteName();

        if ($nextVerificationRoute === null || $request->routeIs($nextVerificationRoute)) {
            return $next($request);
        }

        return redirect()->route($nextVerificationRoute);
    }
}

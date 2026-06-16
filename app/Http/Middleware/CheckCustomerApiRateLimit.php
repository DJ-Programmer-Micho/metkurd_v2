<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\CustomerApi\CustomerApiAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CheckCustomerApiRateLimit
{
    public function __construct(
        protected CustomerApiAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $customer = $request->user();

        if (! $customer instanceof Customer) {
            return $next($request);
        }

        $limit = $this->access->allowedRequestsPerMinute($customer);

        if ($limit < 1) {
            return response()->json([
                'success' => false,
                'message' => 'API access is available only on paid plans.',
                'code' => 'api_access_required',
            ], 403);
        }

        $key = 'customer-api-rate:'.$customer->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded.',
                'code' => 'rate_limit_exceeded',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}

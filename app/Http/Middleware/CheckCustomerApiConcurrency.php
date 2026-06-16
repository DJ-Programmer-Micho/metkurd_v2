<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\CustomerApi\CustomerApiAccessService;
use Closure;
use Illuminate\Http\Request;

class CheckCustomerApiConcurrency
{
    public function __construct(
        protected CustomerApiAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('post')) {
            return $next($request);
        }

        $customer = $request->user();

        if (! $customer instanceof Customer) {
            return $next($request);
        }

        $limit = $this->access->allowedConcurrentJobs($customer);

        if ($limit < 1) {
            return response()->json([
                'success' => false,
                'message' => 'API access is available only on paid plans.',
                'code' => 'api_access_required',
            ], 403);
        }

        if ($this->access->activeApiJobsCount($customer) >= $limit) {
            return response()->json([
                'success' => false,
                'message' => 'You reached your concurrent API job limit for the current plan.',
                'code' => 'concurrency_limit_exceeded',
            ], 409);
        }

        return $next($request);
    }
}

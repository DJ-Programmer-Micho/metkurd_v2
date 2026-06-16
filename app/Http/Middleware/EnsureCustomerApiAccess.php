<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\CustomerApi\CustomerApiAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureCustomerApiAccess
{
    public function __construct(
        protected CustomerApiAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $customer = $request->user();

        if (! $customer instanceof Customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
                'code' => 'unauthorized',
            ], 401);
        }

        if ((int) ($customer->status ?? 0) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Customer account is inactive.',
                'code' => 'customer_inactive',
            ], 423);
        }

        if (! $this->access->customerHasApiAccess($customer)) {
            return response()->json([
                'success' => false,
                'message' => 'API access is available only on paid plans.',
                'code' => 'api_access_required',
            ], 403);
        }

        return $next($request);
    }
}

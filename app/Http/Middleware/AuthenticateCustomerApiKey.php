<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\CustomerApi\CustomerApiKeyService;
use Closure;
use Illuminate\Http\Request;

class AuthenticateCustomerApiKey
{
    public function __construct(
        protected CustomerApiKeyService $keys,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = trim((string) $request->bearerToken());

        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
                'code' => 'unauthorized',
            ], 401);
        }

        $apiKey = $this->keys->findActiveKey($token);

        if (! $apiKey instanceof CustomerApiKey || ! $apiKey->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
                'code' => 'invalid_api_key',
            ], 401);
        }

        $customer = $apiKey->customer;

        if (! $customer instanceof Customer) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
                'code' => 'invalid_api_key',
            ], 401);
        }

        $this->keys->touchUsage($apiKey, $request->ip());

        $request->attributes->set('customerApiKey', $apiKey);
        $request->attributes->set('customerApiCustomer', $customer);
        $request->setUserResolver(fn (): Customer => $customer);

        return $next($request);
    }
}

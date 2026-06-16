<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\CustomerApiScopeService;
use App\Services\CustomerApi\CustomerApiUsageService;
use Illuminate\Http\Request;

class UsageController extends CustomerApiController
{
    public function __construct(
        protected CustomerApiUsageService $usage,
        protected CustomerApiAccessService $access,
        protected CustomerApiScopeService $scopes,
    ) {}

    public function __invoke(Request $request)
    {
        $customer = $this->customer($request);
        $apiKey = $this->apiKey($request);

        if (! $this->scopes->hasScope($apiKey, 'usage:read')) {
            return $this->error('This API key is not authorized for usage reporting.', 'scope_forbidden', 403);
        }

        if (! $this->access->allowsScope($customer, 'usage:read')) {
            return $this->error('Your current plan does not allow this API scope.', 'scope_forbidden', 403);
        }

        return $this->success([
            'usage' => $this->usage->summary($customer),
            'rate_limit' => [
                'requests_per_minute' => $this->access->allowedRequestsPerMinute($customer),
                'concurrent_jobs' => $this->access->allowedConcurrentJobs($customer),
            ],
        ]);
    }
}

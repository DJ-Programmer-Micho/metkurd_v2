<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Services\Billing\CustomerUsageSummaryService;
use App\Services\CustomerApi\CustomerApiAccessService;
use Illuminate\Http\Request;

class MeController extends CustomerApiController
{
    public function __construct(
        protected CustomerApiAccessService $access,
        protected CustomerUsageSummaryService $usage,
    ) {}

    public function __invoke(Request $request)
    {
        $customer = $this->customer($request);
        $apiKey = $this->apiKey($request);
        $config = $this->access->configForCustomer($customer);
        $usage = $this->usage->forApiCustomer($customer);

        $name = trim((string) ($customer->profile?->first_name.' '.$customer->profile?->last_name));
        $name = $name !== '' ? $name : ((string) ($customer->username ?: $customer->email ?: 'Customer'));

        return $this->success([
            'customer' => [
                'id' => (int) $customer->id,
                'name' => $name,
            ],
            'plan' => [
                'name' => (string) $config['plan']->name,
                'code' => (string) $config['plan']->code,
                'api_enabled' => (bool) $config['api_enabled'],
            ],
            'credits' => [
                'balance' => (int) data_get($usage, 'credits.balance', 0),
            ],
            'rate_limit' => [
                'requests_per_minute' => (int) $config['requests_per_minute'],
                'concurrent_jobs' => (int) $config['concurrent_jobs'],
            ],
            'scopes' => array_values((array) ($apiKey->scopes ?? [])),
        ]);
    }
}

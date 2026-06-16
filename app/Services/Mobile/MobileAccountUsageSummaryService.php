<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use App\Services\Billing\CustomerUsageSummaryService;

class MobileAccountUsageSummaryService
{
    public function __construct(
        protected CustomerUsageSummaryService $usage,
        protected MobilePricingMetadataService $pricing,
    ) {}

    /**
     * @return array{
     *     credits: array<string, mixed>,
     *     storage: array<string, mixed>,
     *     pricing: array<string, mixed>,
     *     meta: array<string, mixed>
     * }
     */
    public function forCustomer(Customer $customer): array
    {
        $usage = $this->usage->forCustomer($customer);

        return [
            'credits' => $usage['credits'],
            'storage' => $usage['storage'],
            'pricing' => $this->pricing->forCustomer($customer),
            'meta' => $usage['meta'],
        ];
    }
}

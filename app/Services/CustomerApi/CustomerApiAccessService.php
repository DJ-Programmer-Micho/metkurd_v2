<?php

namespace App\Services\CustomerApi;

use App\Models\ApiJob;
use App\Models\Customer;
use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Services\Billing\CustomerBillingStateService;

class CustomerApiAccessService
{
    protected const CUSTOMER_SCOPE_CATALOG = [
        'tts:apollo-1-0v',
        'tts:apollo-1-5v',
        'tts:delta-1-0v',
        'tts:vector-1-0',
        'tts:vector-1-5',
        'asr:wasr',
        'asr:qasr',
        'caption:qasr',
        'ocr:generate',
        'translation:generate',
        'stem:generate',
        'usage:read',
        'jobs:read',
        'files:download',
    ];

    protected const SCOPE_ACTION_MAP = [
        'tts:apollo-1-0v' => 'tts.standard',
        'tts:apollo-1-5v' => 'xomni.generate',
        'tts:delta-1-0v' => 'ftts.standard',
        'tts:vector-1-0' => 'clone_tts.standard',
        'tts:vector-1-5' => 'clone_xomni.generate',
        'asr:wasr' => 'asr.standard',
        'asr:qasr' => 'qasr.standard',
        'caption:qasr' => 'caption.standard',
        'ocr:generate' => 'ocr.standard',
        'translation:generate' => 'tran.standard',
        'stem:generate' => 'stem.sep2',
    ];

    protected const SCOPE_ALIASES = [
        'tts:apollo-1-0v' => ['tts:xtts'],
        'tts:apollo-1-5v' => ['tts:xomni'],
        'tts:delta-1-0v' => ['tts:f5tts'],
        'tts:vector-1-0' => ['tts:clone-xtts'],
        'tts:vector-1-5' => ['tts:clone-xomni'],
    ];

    public function __construct(
        protected CustomerBillingStateService $billingState,
    ) {}

    public function planForCustomer(Customer $customer): ServicePlan
    {
        return $customer->currentServicePlan() ?: $this->billingState->defaultServicePlan();
    }

    /**
     * @return array{
     *     plan: ServicePlan,
     *     api_enabled: bool,
     *     requests_per_minute: int,
     *     concurrent_jobs: int,
     *     allowed_tools: array<int, string>
     * }
     */
    public function configForCustomer(Customer $customer): array
    {
        $plan = $this->planForCustomer($customer);
        $isFreePlan = (bool) ($plan->is_free ?? false);

        return [
            'plan' => $plan,
            'api_enabled' => $isFreePlan ? false : (bool) ($plan->api_enabled ?? false),
            'requests_per_minute' => $isFreePlan ? 0 : max(0, (int) ($plan->api_requests_per_minute ?? 0)),
            'concurrent_jobs' => $isFreePlan ? 0 : max(0, (int) ($plan->api_concurrent_jobs ?? 0)),
            'allowed_tools' => $isFreePlan
                ? []
                : collect((array) ($plan->api_allowed_tools ?? []))
                    ->map(fn (mixed $scope): string => self::canonicalScope((string) $scope))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
        ];
    }

    public function customerHasApiAccess(Customer $customer): bool
    {
        $config = $this->configForCustomer($customer);

        return (int) ($customer->status ?? 0) === 1
            && (bool) $config['api_enabled']
            && $this->availableScopesForCustomer($customer) !== [];
    }

    public function allowedRequestsPerMinute(Customer $customer): int
    {
        return (int) $this->configForCustomer($customer)['requests_per_minute'];
    }

    public function allowedConcurrentJobs(Customer $customer): int
    {
        return (int) $this->configForCustomer($customer)['concurrent_jobs'];
    }

    /**
     * @return array<int, string>
     */
    public function allowedTools(Customer $customer): array
    {
        return (array) $this->configForCustomer($customer)['allowed_tools'];
    }

    /**
     * @return array<int, string>
     */
    public function availableScopesForCustomer(Customer $customer): array
    {
        $configuredScopes = $this->allowedTools($customer);

        if ($configuredScopes === []) {
            return [];
        }

        $requestedScopes = collect(self::CUSTOMER_SCOPE_CATALOG)
            ->filter(fn (string $scope): bool => $this->scopeAllowedByConfiguration($configuredScopes, $scope))
            ->values();

        if ($requestedScopes->contains(fn (string $scope): bool => ! in_array($scope, ['usage:read', 'jobs:read', 'files:download'], true))) {
            $requestedScopes->push('jobs:read', 'files:download');
        }

        return $requestedScopes
            ->filter(fn (string $scope): bool => $this->scopeAllowedByEntitlement($customer, $scope))
            ->unique()
            ->values()
            ->all();
    }

    public function allowsScope(Customer $customer, string $scope): bool
    {
        $scope = self::canonicalScope($scope);

        if ($scope === '') {
            return true;
        }

        return in_array($scope, $this->availableScopesForCustomer($customer), true);
    }

    public function activeApiJobsCount(Customer $customer): int
    {
        return ApiJob::query()
            ->where('customer_id', (int) $customer->id)
            ->whereIn('status', ['queued', 'processing'])
            ->count();
    }

    public function scopeForActionCode(string $actionCode): ?string
    {
        $actionCode = strtolower(trim($actionCode));

        return match ($actionCode) {
            'stem.sep4' => 'stem:generate',
            default => array_search($actionCode, self::SCOPE_ACTION_MAP, true) ?: null,
        };
    }

    public static function customerScopeCatalog(): array
    {
        return self::CUSTOMER_SCOPE_CATALOG;
    }

    public static function canonicalScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        if ($scope === '') {
            return '';
        }

        foreach (self::SCOPE_ALIASES as $canonical => $aliases) {
            if ($scope === $canonical || in_array($scope, $aliases, true)) {
                return $canonical;
            }
        }

        return $scope;
    }

    public static function equivalentScopes(string $scope): array
    {
        $canonical = self::canonicalScope($scope);

        if ($canonical === '') {
            return [];
        }

        return array_values(array_unique([
            $canonical,
            ...array_values(self::SCOPE_ALIASES[$canonical] ?? []),
        ]));
    }

    protected function scopeAllowedByConfiguration(array $configuredScopes, string $scope): bool
    {
        $configuredScopes = collect($configuredScopes)
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        if ($configuredScopes === []) {
            return false;
        }

        foreach (self::equivalentScopes($scope) as $candidate) {
            if (in_array('*', $configuredScopes, true) || in_array($candidate, $configuredScopes, true)) {
                return true;
            }
        }

        [$prefix] = array_pad(explode(':', self::canonicalScope($scope), 2), 2, null);

        return $prefix !== null && in_array($prefix.':*', $configuredScopes, true);
    }

    protected function scopeAllowedByEntitlement(Customer $customer, string $scope): bool
    {
        $actionCode = self::SCOPE_ACTION_MAP[self::canonicalScope($scope)] ?? null;

        if ($actionCode === null) {
            return true;
        }

        return $customer->isAllowed($actionCode, PlanEntitlement::CHANNEL_API);
    }
}

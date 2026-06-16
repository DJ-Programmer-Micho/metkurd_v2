<?php

namespace App\Services\CustomerApi;

use App\Models\CustomerApiKey;

class CustomerApiScopeService
{
    public function __construct(
        protected CustomerApiAccessService $access,
    ) {}

    public function hasScope(CustomerApiKey $apiKey, string $scope): bool
    {
        $scope = CustomerApiAccessService::canonicalScope($scope);

        if ($scope === '') {
            return true;
        }

        $scopes = collect((array) ($apiKey->scopes ?? []))
            ->map(fn (mixed $item): string => strtolower(trim((string) $item)))
            ->filter()
            ->values()
            ->all();

        if (in_array('*', $scopes, true)) {
            return true;
        }

        foreach (CustomerApiAccessService::equivalentScopes($scope) as $candidate) {
            if (in_array($candidate, $scopes, true)) {
                return true;
            }
        }

        [$prefix] = array_pad(explode(':', $scope, 2), 2, null);

        return $prefix !== null && in_array($prefix.':*', $scopes, true);
    }
}

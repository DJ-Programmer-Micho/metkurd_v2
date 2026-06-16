<?php

namespace App\Services\CustomerApi;

use App\Models\Customer;
use App\Models\CustomerApiKey;
use Illuminate\Support\Str;

class CustomerApiKeyService
{
    public function __construct(
        protected CustomerApiAccessService $access,
    ) {}

    public function issue(Customer $customer, string $name, array $scopes = ['*']): array
    {
        if (! $this->access->customerHasApiAccess($customer)) {
            throw new \RuntimeException('API access is available only on paid plans.');
        }

        $plain = (string) config('customer_api.key_prefix', 'mk_live_').Str::random(40);
        $availableScopes = $this->access->availableScopesForCustomer($customer);
        $normalizedScopes = collect($scopes)
            ->map(fn (mixed $scope): string => CustomerApiAccessService::canonicalScope((string) $scope))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $scopes = in_array('*', $normalizedScopes, true) || $normalizedScopes === []
            ? $availableScopes
            : array_values(array_intersect($normalizedScopes, $availableScopes));

        $apiKey = CustomerApiKey::create([
            'customer_id' => (int) $customer->id,
            'name' => trim($name) !== '' ? trim($name) : 'API Key',
            'key_prefix' => substr($plain, 0, 16),
            'key_hash' => $this->hashKey($plain),
            'scopes' => array_values(array_unique(array_filter(array_map(
                fn (mixed $scope): string => strtolower(trim((string) $scope)),
                $scopes
            )))),
            'status' => 'active',
        ]);

        return [
            'api_key' => $apiKey,
            'plain_text_key' => $plain,
        ];
    }

    public function findActiveKey(string $plainTextKey): ?CustomerApiKey
    {
        $plainTextKey = trim($plainTextKey);

        if ($plainTextKey === '') {
            return null;
        }

        return CustomerApiKey::query()
            ->with('customer.profile')
            ->where('key_hash', $this->hashKey($plainTextKey))
            ->first();
    }

    public function touchUsage(CustomerApiKey $apiKey, ?string $ipAddress = null): void
    {
        $apiKey->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ipAddress,
        ])->save();
    }

    public function activeKeysCount(Customer $customer): int
    {
        return CustomerApiKey::query()
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->count();
    }

    public function revoke(CustomerApiKey $apiKey): CustomerApiKey
    {
        $apiKey->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        return $apiKey->fresh() ?: $apiKey;
    }

    public function hashKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }
}

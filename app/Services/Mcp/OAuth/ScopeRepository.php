<?php

namespace App\Services\Mcp\OAuth;

use App\Models\Customer;
use App\Models\CustomerMcpConnection;
use App\Services\Mcp\CustomerMcpAccessService;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class ScopeRepository extends \Laravel\Passport\Bridge\ScopeRepository
{
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        $customer = Customer::find($userIdentifier);
        $connection = CustomerMcpConnection::where('customer_id', $userIdentifier)->where('client_id', $clientEntity->getIdentifier())->first();
        if (! in_array($grantType, ['authorization_code', 'refresh_token'], true) || ! $customer || ! $connection
            || $connection->status !== 'active' || $connection->revoked_at) {
            throw OAuthServerException::accessDenied('Connection unavailable.');
        }
        $allowed = app(CustomerMcpAccessService::class)->scopes($customer);
        $requested = array_map(fn ($scope) => $scope->getIdentifier(), $scopes);
        if (! $requested) {
            throw OAuthServerException::invalidScope('Requested permissions are unavailable.');
        }
        if (array_diff($requested, $connection->scopes) || array_diff($requested, $allowed)) {
            throw OAuthServerException::accessDenied('Permissions unavailable.');
        }

        return parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);
    }
}

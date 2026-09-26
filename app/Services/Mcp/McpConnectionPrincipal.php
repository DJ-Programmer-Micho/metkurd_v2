<?php

namespace App\Services\Mcp;

use App\Models\Customer;
use App\Models\CustomerMcpConnection;
use App\Services\CustomerApi\V2\ApiProblem;
use App\Services\CustomerApi\V2\ExternalClientPrincipal;

final readonly class McpConnectionPrincipal implements ExternalClientPrincipal
{
    public function __construct(public string $connectionId, public array $tokenScopes) {}

    public function customer(): Customer
    {
        $connection = CustomerMcpConnection::find($this->connectionId);
        if (! $connection || $connection->revoked_at || $connection->status !== 'active'
            || ! \Illuminate\Support\Facades\DB::table('oauth_clients')->where('id', $connection->client_id)->where('revoked', false)->exists()) {
            throw new ApiProblem('connection_revoked', 401);
        }
        $customer = Customer::find($connection->customer_id);
        if (! $customer) {
            throw new ApiProblem('connection_revoked', 401);
        }
        app(CustomerMcpAccessService::class)->assertEligible($customer);

        return $customer;
    }

    public function authorize(Customer $customer, string $scope, ?string $action = null): void
    {
        $current = $this->customer();
        $connection = CustomerMcpConnection::findOrFail($this->connectionId);
        if ($current->id !== $customer->id || ! in_array($scope, $this->tokenScopes, true)
            || ! in_array($scope, $connection->scopes, true)
            || ! in_array($scope, app(CustomerMcpAccessService::class)->scopes($current), true)
            || ($action && ! $current->isAllowed($action, 'api'))) {
            throw new ApiProblem('scope_not_allowed', 403);
        }
    }

    public function apiKeyId(): ?int
    {
        return null;
    }
}

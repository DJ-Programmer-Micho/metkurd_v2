<?php

namespace App\Services\Mcp\OAuth;

use App\Models\Customer;
use App\Models\CustomerMcpConnection;
use Illuminate\Support\Facades\DB;

class Connections
{
    public function revoke(Customer $customer, string $id): void
    {
        DB::transaction(function () use ($customer, $id) {
            $clientId = CustomerMcpConnection::where('customer_id', $customer->id)->findOrFail($id)->client_id;
            DB::table('oauth_clients')->where('id', $clientId)->lockForUpdate()->firstOrFail();
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $connection = CustomerMcpConnection::where('customer_id', $customer->id)->lockForUpdate()->findOrFail($id);
            $this->revokeTokens($customer->id, $connection->client_id);
            $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
        });
    }

    public function revokeTokens(int $customerId, string $clientId): void
    {
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $customerId)->where('client_id', $clientId);
        $this->revokeMatchingTokens($tokens, DB::table('oauth_auth_codes')->where('user_id', $customerId)->where('client_id', $clientId));
    }

    public function revokeClientTokens(string $clientId): void
    {
        $this->revokeMatchingTokens(DB::table('oauth_access_tokens')->where('client_id', $clientId),
            DB::table('oauth_auth_codes')->where('client_id', $clientId));
    }

    private function revokeMatchingTokens(\Illuminate\Database\Query\Builder $tokens, \Illuminate\Database\Query\Builder $codes): void
    {
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        $codes->update(['revoked' => true]);
    }
}

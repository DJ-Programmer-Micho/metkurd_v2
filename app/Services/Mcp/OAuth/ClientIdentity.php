<?php

namespace App\Services\Mcp\OAuth;

use App\Models\CustomerMcpConnection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;

class ClientIdentity
{
    public function resolve(string $id, bool $discover = true): ?Client
    {
        if (! str_starts_with($id, 'https://')) {
            return Client::whereKey($id)->where('provider', 'customers')->whereNull('secret')->where('revoked', false)->first();
        }
        if (! $discover && ! Client::whereKey($id)->whereNotNull('mcp_metadata_hash')->where('revoked', false)->exists()) {
            return null;
        }
        $metadata = app(ClientMetadata::class)->fetch($id);
        $hash = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR));
        $scopes = $metadata['scope'] === null ? null : preg_split('/\s+/', trim($metadata['scope']));

        return DB::transaction(function () use ($id, $metadata, $hash, $scopes) {
            // Store the fetched identity itself, never substitute a generated UUID.
            DB::table('oauth_clients')->insertOrIgnore(['id' => $id, 'name' => $metadata['name'], 'secret' => null,
                'provider' => 'customers', 'redirect_uris' => json_encode($metadata['redirect_uris']),
                'grant_types' => json_encode($metadata['grant_types']), 'revoked' => false,
                'scopes' => $scopes === null ? null : json_encode($scopes),
                'mcp_application_type' => $metadata['application_type'], 'mcp_metadata_hash' => $hash,
                'created_at' => now(), 'updated_at' => now()]);
            $client = Client::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($client->revoked || $client->provider !== 'customers' || $client->secret !== null || ! $client->mcp_metadata_hash) {
                return null;
            }
            if (! hash_equals($client->mcp_metadata_hash, $hash)) {
                app(Connections::class)->revokeClientTokens($id);
                CustomerMcpConnection::where('client_id', $id)->update(['status' => 'revoked', 'revoked_at' => now()]);
                $client->forceFill(['name' => $metadata['name'], 'redirect_uris' => $metadata['redirect_uris'],
                    'grant_types' => $metadata['grant_types'], 'mcp_application_type' => $metadata['application_type'],
                    'scopes' => $scopes,
                    'mcp_metadata_hash' => $hash])->save();
            }

            return $client;
        });
    }
}

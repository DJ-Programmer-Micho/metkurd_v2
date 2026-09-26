<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CIMD URLs are the actual OAuth identity, including in codes and tokens.
        // ASCII binary comparison preserves URL case and keeps MySQL indexes bounded.
        foreach (['oauth_clients' => 'id', 'oauth_auth_codes' => 'client_id', 'oauth_access_tokens' => 'client_id', 'customer_mcp_connections' => 'client_id'] as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $field = $blueprint->string($column, 512);
                if (Schema::getConnection()->getDriverName() === 'mysql') {
                    $field->charset('ascii')->collation('ascii_bin');
                }
                $field->change();
            });
        }
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->json('scopes')->nullable();
            $table->string('mcp_application_type', 16)->default('web');
            $table->string('mcp_metadata_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Never truncate URL identities or silently reinterpret retained grants.
        // Disable the feature to roll back; retain this additive schema and history.
    }
};

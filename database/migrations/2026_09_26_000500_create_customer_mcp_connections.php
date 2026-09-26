<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_mcp_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->uuid('client_id')->index();
            $table->string('name');
            $table->json('scopes');
            $table->string('status')->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'client_id']);
        });
        Schema::table('api_jobs', fn (Blueprint $table) => $table->unsignedBigInteger('api_key_id')->nullable()->change());
        Schema::create('mcp_upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->uuid('connection_id');
            $table->string('purpose', 32);
            $table->foreignId('customer_file_id')->nullable()->constrained('customer_files')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // api_jobs may contain MCP history. Keep the nullable key on rollback.
        Schema::dropIfExists('mcp_upload_sessions');
        Schema::dropIfExists('customer_mcp_connections');
    }
};

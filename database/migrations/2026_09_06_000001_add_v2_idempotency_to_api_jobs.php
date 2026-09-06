<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_jobs', function (Blueprint $table) {
            $table->char('idempotency_hash', 64)->nullable();
            $table->unique(['customer_id', 'idempotency_hash'], 'api_jobs_customer_v2_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('api_jobs', function (Blueprint $table) {
            $table->dropUnique('api_jobs_customer_v2_idempotency_unique');
            $table->dropColumn('idempotency_hash');
        });
    }
};

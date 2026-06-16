<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_api_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('name', 120);
            $table->string('key_prefix', 40)->index();
            $table->string('key_hash', 64)->unique();
            $table->json('scopes')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->string('last_used_ip', 64)->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::create('api_jobs', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('api_key_id')->index();
            $table->uuid('ml_job_id')->nullable()->index();
            $table->string('tool_code', 60)->index();
            $table->string('tool_action', 130)->nullable()->index();
            $table->string('engine', 60)->nullable()->index();
            $table->string('status', 20)->default('queued')->index();
            $table->string('input_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('estimated_credits')->default(0);
            $table->unsignedBigInteger('reserved_credits')->default(0);
            $table->unsignedBigInteger('final_credits')->default(0);
            $table->string('storage_mode', 20)->default('temporary')->index();
            $table->string('error_code', 80)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('api_key_id')->references('id')->on('customer_api_keys')->cascadeOnDelete();
        });

        Schema::create('api_usage_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('api_key_id')->nullable()->index();
            $table->string('api_job_id', 40)->nullable()->index();
            $table->string('endpoint', 190);
            $table->string('method', 10);
            $table->string('metric_code', 50)->nullable()->index();
            $table->decimal('metric_quantity', 14, 4)->nullable();
            $table->unsignedBigInteger('credits_charged')->default(0);
            $table->string('status', 20)->default('received')->index();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('idempotency_key', 190)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('api_key_id')->references('id')->on('customer_api_keys')->nullOnDelete();
            $table->unique(
                ['customer_id', 'api_key_id', 'endpoint', 'method', 'idempotency_key'],
                'api_usage_logs_customer_key_endpoint_idempotency_unique'
            );
        });

        Schema::create('api_credit_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('api_job_id', 40)->index();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('reserved')->index();
            $table->json('meta')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->unique('api_job_id');
        });

        Schema::create('api_result_files', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('api_job_id', 40)->index();
            $table->unsignedBigInteger('storage_file_id')->index();
            $table->string('result_kind', 40)->default('primary');
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('storage_file_id')->references('id')->on('customer_files')->cascadeOnDelete();
            $table->unique(['api_job_id', 'storage_file_id', 'result_kind'], 'api_result_files_job_storage_kind_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_result_files');
        Schema::dropIfExists('api_credit_reservations');
        Schema::dropIfExists('api_usage_logs');
        Schema::dropIfExists('api_jobs');
        Schema::dropIfExists('customer_api_keys');
    }
};

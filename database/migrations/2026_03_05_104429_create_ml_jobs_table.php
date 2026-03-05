<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ml_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('customer_id')->index();

            $table->unsignedBigInteger('tool_id')->nullable()->index();
            $table->unsignedBigInteger('tool_action_id')->nullable()->index();

            $table->string('status', 20)->default('queued')->index(); // queued,running,succeeded,failed,canceled

            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();

            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedBigInteger('storage_in_bytes')->default(0);
            $table->unsignedBigInteger('storage_out_bytes')->default(0);

            // Provider tracking
            $table->string('provider', 40)->nullable()->index(); // runpod
            $table->string('provider_job_id', 190)->nullable()->index();

            $table->decimal('provider_cost_usd', 12, 6)->nullable(); // runpod cost
            $table->unsignedInteger('cold_start_ms')->nullable();     // 30-50s = 30000-50000ms
            $table->unsignedInteger('runtime_ms')->nullable();        // ~3000ms

            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('tool_id')->references('id')->on('tools')->nullOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->nullOnDelete();

            $table->index(['customer_id','status','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_jobs');
    }
};
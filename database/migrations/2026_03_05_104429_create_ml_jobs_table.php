<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('customer_id')->index();

            $table->unsignedBigInteger('tool_id')->nullable()->index();
            $table->unsignedBigInteger('tool_action_id')->nullable()->index();

            // queued, running, saving, succeeded, failed, canceled
            $table->string('status', 20)->default('queued')->index();

            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();

            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedBigInteger('storage_in_bytes')->default(0);
            $table->unsignedBigInteger('storage_out_bytes')->default(0);

            // Provider tracking
            $table->string('provider', 40)->nullable()->index(); // runpod
            $table->string('provider_job_id', 190)->nullable()->index();
            $table->string('input_hash', 64)->nullable();

            $table->decimal('provider_cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('cold_start_ms')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();

            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            /*
            |--------------------------------------------------------------------------
            | SPA / execution lock fields
            |--------------------------------------------------------------------------
            | These are used mainly for clone_tts so the same customer cannot run
            | the same locked process from another browser/device while active.
            */
            $table->string('job_kind', 40)->nullable()->index(); // tts, clone_tts, asr ...
            $table->string('execution_scope', 40)->nullable()->index(); // browser, device, session
            $table->string('locked_by_session_id', 190)->nullable()->index();
            $table->string('locked_by_fingerprint', 190)->nullable()->index();
            $table->timestamp('lock_expires_at')->nullable()->index();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('tool_id')->references('id')->on('tools')->nullOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->nullOnDelete();

            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['customer_id', 'job_kind', 'status']);
            $table->index(['customer_id', 'job_kind', 'lock_expires_at']);
            $table->index(['customer_id', 'job_kind', 'input_hash'], 'ml_jobs_customer_kind_input_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_jobs');
    }
};

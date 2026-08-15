<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_jobs', function (Blueprint $table): void {
            $table->string('submission_key', 64)->nullable()->after('provider_job_id');
            $table->string('endpoint_key', 40)->nullable()->after('submission_key');
            $table->string('model_key', 40)->nullable()->after('endpoint_key');
            $table->string('charge_reference', 120)->nullable()->after('model_key');
            $table->string('refund_reference', 120)->nullable()->after('charge_reference');
            $table->string('failure_stage', 60)->nullable()->after('refund_reference');
            $table->timestamp('submission_attempted_at')->nullable()->after('started_at');
            $table->timestamp('refunded_at')->nullable()->after('submission_attempted_at');

            $table->unique(['customer_id', 'submission_key'], 'ml_jobs_customer_submission_key_unique');
            $table->unique('charge_reference', 'ml_jobs_charge_reference_unique');
            $table->unique('refund_reference', 'ml_jobs_refund_reference_unique');
            $table->index(['status', 'submission_attempted_at'], 'ml_jobs_status_submission_attempted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ml_jobs', function (Blueprint $table): void {
            $table->dropIndex('ml_jobs_status_submission_attempted_idx');
            $table->dropUnique('ml_jobs_refund_reference_unique');
            $table->dropUnique('ml_jobs_charge_reference_unique');
            $table->dropUnique('ml_jobs_customer_submission_key_unique');
            $table->dropColumn([
                'submission_key', 'endpoint_key', 'model_key', 'charge_reference',
                'refund_reference', 'failure_stage', 'submission_attempted_at', 'refunded_at',
            ]);
        });
    }
};

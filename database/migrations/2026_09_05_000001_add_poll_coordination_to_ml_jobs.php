<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_jobs', function (Blueprint $table) {
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('poll_locked_until')->nullable();
            $table->uuid('poll_token')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->index(['status', 'next_poll_at'], 'ml_jobs_reconciliation_due');
        });
    }

    public function down(): void
    {
        Schema::table('ml_jobs', function (Blueprint $table) {
            $table->dropIndex('ml_jobs_reconciliation_due');
            $table->dropColumn(['next_poll_at', 'poll_locked_until', 'poll_token', 'poll_attempts']);
        });
    }
};

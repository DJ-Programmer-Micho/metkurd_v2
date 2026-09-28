<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plan_agreements', function (Blueprint $table) {
            // Null preserves existing agreements' plan concurrency.
            $table->unsignedSmallInteger('concurrent_jobs_limit')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('service_plan_agreements')->whereNotNull('concurrent_jobs_limit')->exists()) {
            throw new RuntimeException('Retain approved agreement concurrency before rollback.');
        }
        Schema::table('service_plan_agreements', fn (Blueprint $table) => $table->dropColumn('concurrent_jobs_limit'));
    }
};

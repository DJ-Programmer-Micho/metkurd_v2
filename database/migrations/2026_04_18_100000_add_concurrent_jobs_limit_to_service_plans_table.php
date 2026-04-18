<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_plans', 'concurrent_jobs_limit')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->unsignedSmallInteger('concurrent_jobs_limit')
                    ->default(2)
                    ->after('monthly_credits');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_plans', 'concurrent_jobs_limit')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->dropColumn('concurrent_jobs_limit');
            });
        }
    }
};

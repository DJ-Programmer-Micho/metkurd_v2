<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_plans', 'api_enabled')) {
                $table->boolean('api_enabled')->default(false)->after('concurrent_jobs_limit')->index();
            }

            if (! Schema::hasColumn('service_plans', 'api_requests_per_minute')) {
                $table->unsignedInteger('api_requests_per_minute')->default(0)->after('api_enabled');
            }

            if (! Schema::hasColumn('service_plans', 'api_concurrent_jobs')) {
                $table->unsignedSmallInteger('api_concurrent_jobs')->default(0)->after('api_requests_per_minute');
            }

            if (! Schema::hasColumn('service_plans', 'api_allowed_tools')) {
                $table->json('api_allowed_tools')->nullable()->after('api_concurrent_jobs');
            }
        });

        $paidTools = json_encode([
            'tts:apollo-1-0v',
            'tts:apollo-1-5v',
            'tts:delta-1-0v',
            'tts:vector-1-0',
            'tts:vector-1-5',
            'asr:wasr',
            'asr:qasr',
            'caption:qasr',
            'ocr:generate',
            'translation:generate',
            'stem:generate',
            'usage:read',
        ], JSON_THROW_ON_ERROR);

        DB::table('service_plans')
            ->where('code', 'free')
            ->update([
                'api_enabled' => false,
                'api_requests_per_minute' => 0,
                'api_concurrent_jobs' => 0,
                'api_allowed_tools' => json_encode([], JSON_THROW_ON_ERROR),
            ]);

        DB::table('service_plans')
            ->where('code', 'student')
            ->update([
                'api_enabled' => true,
                'api_requests_per_minute' => 60,
                'api_concurrent_jobs' => 2,
                'api_allowed_tools' => $paidTools,
            ]);

        DB::table('service_plans')
            ->where('code', 'pro')
            ->update([
                'api_enabled' => true,
                'api_requests_per_minute' => 300,
                'api_concurrent_jobs' => 10,
                'api_allowed_tools' => $paidTools,
            ]);

        DB::table('service_plans')
            ->where('code', 'premium')
            ->update([
                'api_enabled' => true,
                'api_requests_per_minute' => 1000,
                'api_concurrent_jobs' => 50,
                'api_allowed_tools' => $paidTools,
            ]);
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table): void {
            foreach (['api_allowed_tools', 'api_concurrent_jobs', 'api_requests_per_minute', 'api_enabled'] as $column) {
                if (Schema::hasColumn('service_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

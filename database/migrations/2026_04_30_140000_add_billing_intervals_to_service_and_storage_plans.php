<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_plans') && ! Schema::hasColumn('service_plans', 'billing_intervals')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->json('billing_intervals')->nullable();
            });
        }

        if (Schema::hasTable('storage_plans') && ! Schema::hasColumn('storage_plans', 'billing_intervals')) {
            Schema::table('storage_plans', function (Blueprint $table) {
                $table->json('billing_intervals')->nullable();
            });
        }

        $this->backfillServicePlanIntervals();
        $this->backfillStoragePlanIntervals();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_plans') && Schema::hasColumn('service_plans', 'billing_intervals')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->dropColumn('billing_intervals');
            });
        }

        if (Schema::hasTable('storage_plans') && Schema::hasColumn('storage_plans', 'billing_intervals')) {
            Schema::table('storage_plans', function (Blueprint $table) {
                $table->dropColumn('billing_intervals');
            });
        }
    }

    private function backfillServicePlanIntervals(): void
    {
        if (! Schema::hasTable('service_plans') || ! Schema::hasColumn('service_plans', 'billing_intervals')) {
            return;
        }

        DB::table('service_plans')
            ->select('id', 'billing_interval', 'billing_intervals')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $existing = $this->normalizeStoredIntervals(
                        $row->billing_intervals ?? null,
                        ['monthly', 'yearly', 'lifetime']
                    );

                    if ($existing !== []) {
                        continue;
                    }

                    $interval = strtolower(trim((string) ($row->billing_interval ?? '')));
                    $intervals = match ($interval) {
                        'lifetime' => ['lifetime'],
                        'yearly' => ['yearly'],
                        'monthly' => ['monthly', 'yearly'],
                        default => ['monthly'],
                    };

                    DB::table('service_plans')
                        ->where('id', $row->id)
                        ->update(['billing_intervals' => json_encode($intervals)]);
                }
            });
    }

    private function backfillStoragePlanIntervals(): void
    {
        if (! Schema::hasTable('storage_plans') || ! Schema::hasColumn('storage_plans', 'billing_intervals')) {
            return;
        }

        $hasLegacyInterval = Schema::hasColumn('storage_plans', 'billing_interval');

        DB::table('storage_plans')
            ->select(
                'id',
                'billing_intervals',
                ...($hasLegacyInterval ? ['billing_interval'] : [])
            )
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($hasLegacyInterval): void {
                foreach ($rows as $row) {
                    $existing = $this->normalizeStoredIntervals(
                        $row->billing_intervals ?? null,
                        ['monthly', 'yearly']
                    );

                    if ($existing !== []) {
                        continue;
                    }

                    $interval = $hasLegacyInterval
                        ? strtolower(trim((string) ($row->billing_interval ?? '')))
                        : 'monthly';

                    if (! in_array($interval, ['monthly', 'yearly'], true)) {
                        $interval = 'monthly';
                    }

                    DB::table('storage_plans')
                        ->where('id', $row->id)
                        ->update(['billing_intervals' => json_encode([$interval])]);
                }
            });
    }

    private function normalizeStoredIntervals(mixed $value, array $allowed): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }
};

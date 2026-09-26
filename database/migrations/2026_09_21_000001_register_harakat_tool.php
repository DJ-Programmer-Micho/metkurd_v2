<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        DB::transaction(function () {
            $source = DB::table('tool_actions')->where('full_code', 'xomni-v2.generate')->value('id');
            if (! $source || ! DB::table('pricing_rules')->where('tool_action_id', $source)->where('is_active', true)->exists()) {
                throw new RuntimeException('Harakat registration requires configured xomni-v2.generate pricing.');
            }
            DB::table('tools')->insertOrIgnore([
                'code' => 'harakat', 'name' => 'Harakat 1.0', 'is_active' => true, 'sort_order' => 17,
                'meta' => json_encode(['category' => 'ocr', 'storage_folder' => 'harakat', 'runpod_endpoint_ref' => 'runpod.endpoints.tashkeel_v1', 'source_mode' => 'text']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created = DB::table('tool_actions')->insertOrIgnore([
                'full_code' => 'harakat.diacritize', 'tool_code' => 'harakat', 'action_code' => 'diacritize',
                'name' => 'Harakat 1.0 Diacritize', 'default_metric_code' => 'character', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $created) {
                return;
            }
            $target = DB::table('tool_actions')->where('full_code', 'harakat.diacritize')->value('id');
            foreach (['pricing_rules', 'plan_entitlements'] as $table) {
                foreach (DB::table($table)->where('tool_action_id', $source)->get() as $sourceRow) {
                    $row = (array) $sourceRow;
                    unset($row['id']);
                    $row['tool_action_id'] = $target;
                    $row['created_at'] = $row['updated_at'] = now();
                    DB::table($table)->insert($row);
                }
            }
        });
    }

    public function down(): void
    {
        // Preserve stable identities referenced by jobs and financial history.
    }
};

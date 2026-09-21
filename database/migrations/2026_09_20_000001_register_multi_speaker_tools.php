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
            foreach (['zeta' => ['Zeta 1.0v', 'xomni-v2', 'builtin_ref_batch'], 'theta' => ['Theta 1.0v', 'vector-v2', 'audio_url_batch']] as $code => [$name, $source, $mode]) {
                // Snapshot the existing launch economics, including channel/plan rules.
                // Never rewrite existing target economics on a re-run.
                $sourceId = DB::table('tool_actions')->where('full_code', $source.'.generate')->value('id');
                if (! $sourceId || ! DB::table('pricing_rules')->where('tool_action_id', $sourceId)->where('is_active', true)->exists()) {
                    throw new RuntimeException('Multi-speaker registration requires configured '.$source.'.generate pricing.');
                }
                DB::table('tools')->insertOrIgnore([
                    'code' => $code, 'name' => $name, 'is_active' => true, 'sort_order' => $code === 'zeta' ? 15 : 16,
                    'meta' => json_encode(['category' => 'speech', 'storage_folder' => $code, 'runpod_endpoint_ref' => 'runpod.endpoints.omni_v2', 'model' => 'model_2', 'mode' => $mode]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $created = DB::table('tool_actions')->insertOrIgnore([
                    'full_code' => $code.'.generate', 'tool_code' => $code, 'action_code' => 'generate',
                    'name' => $name.' Generate', 'default_metric_code' => 'character', 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! $created) {
                    continue;
                }
                $targetId = DB::table('tool_actions')->where('full_code', $code.'.generate')->value('id');
                foreach (['pricing_rules', 'plan_entitlements'] as $table) {
                    foreach (DB::table($table)->where('tool_action_id', $sourceId)->get() as $sourceRow) {
                        $row = (array) $sourceRow;
                        unset($row['id']);
                        $row['tool_action_id'] = $targetId;
                        $row['created_at'] = $row['updated_at'] = now();
                        DB::table($table)->insert($row);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Job/ledger history may refer to these identities. Use forward changes.
    }
};

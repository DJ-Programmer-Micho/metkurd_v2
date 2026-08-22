<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            DB::table('tools')->updateOrInsert(['code' => 'vector-v2'], [
                'name' => 'Vector 2.0v', 'is_active' => true, 'sort_order' => 14,
                'meta' => json_encode(['category' => 'speech', 'storage_folder' => 'vector-v2', 'runpod_endpoint_ref' => 'runpod.endpoints.omni', 'worker_type' => 'clone_xomni', 'default_language' => 'ckb', 'languages' => ['ckb', 'en', 'ar'], 'output_formats' => ['wav']], JSON_THROW_ON_ERROR),
                'updated_at' => $now, 'created_at' => $now,
            ]);
            DB::table('tool_actions')->updateOrInsert(['full_code' => 'vector-v2.generate'], [
                'tool_code' => 'vector-v2', 'action_code' => 'generate', 'name' => 'Vector 2.0v Generate', 'default_metric_code' => 'character', 'is_active' => true,
                'meta' => json_encode(['storage_folder' => 'vector-v2', 'worker_type' => 'clone_xomni', 'endpoint_ref' => 'runpod.endpoints.omni', 'default_language' => 'ckb', 'languages' => ['ckb', 'en', 'ar'], 'output_formats' => ['wav'], 'payload_defaults' => ['mode' => 'audio_url', 'output_format' => 'wav', 'return_base64' => true, 'ref_text' => '', 'ref_max_sec' => 20]], JSON_THROW_ON_ERROR),
                'updated_at' => $now, 'created_at' => $now,
            ]);
            $actionId = (int) DB::table('tool_actions')->where('full_code', 'vector-v2.generate')->value('id');
            foreach (DB::table('service_plans')->pluck('id') as $planId) {
                DB::table('plan_entitlements')->updateOrInsert(['service_plan_id' => (int) $planId, 'tool_action_id' => $actionId, 'entitlement_channel' => 'all'], ['allowed' => true, 'limits' => null, 'updated_at' => $now, 'created_at' => $now]);
            }
            DB::table('pricing_rules')->updateOrInsert(['tool_action_id' => $actionId, 'service_plan_id' => null, 'pricing_channel' => 'all', 'priority' => 100], [
                'rule_scope' => 'global', 'rule_type' => 'unit', 'metric_code' => 'character', 'unit_size' => 1, 'credits_per_unit' => 1.2, 'rounding_mode' => 'ceil', 'rounding_step' => 1, 'minimum_credits' => 1, 'conditions' => null, 'config' => null, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $actionId = DB::table('tool_actions')->where('full_code', 'vector-v2.generate')->value('id');
            if ($actionId !== null) {
                DB::table('pricing_rules')->where('tool_action_id', $actionId)->delete();
                DB::table('plan_entitlements')->where('tool_action_id', $actionId)->delete();
                DB::table('tool_actions')->where('id', $actionId)->delete();
            }
            DB::table('tools')->where('code', 'vector-v2')->delete();
        });
    }
};

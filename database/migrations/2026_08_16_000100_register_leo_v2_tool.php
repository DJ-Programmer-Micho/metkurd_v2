<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            DB::table('tools')->updateOrInsert(['code' => 'leo'], [
                'name' => 'Leo', 'is_active' => true, 'sort_order' => 15,
                'meta' => json_encode(['category' => 'speech', 'storage_folder' => 'leo', 'runpod_endpoint_ref' => 'runpod.endpoints.qasr_v2', 'worker_type' => 'asr', 'default_language' => 'ckb', 'languages' => ['ckb', 'en', 'ar'], 'output_formats' => ['txt']], JSON_THROW_ON_ERROR),
                'updated_at' => $now, 'created_at' => $now,
            ]);
            DB::table('tool_actions')->updateOrInsert(['full_code' => 'leo.transcribe'], [
                'tool_code' => 'leo', 'action_code' => 'transcribe', 'name' => 'Leo Transcribe', 'default_metric_code' => 'minute', 'is_active' => true,
                'meta' => json_encode(['storage_folder' => 'leo', 'worker_type' => 'asr', 'endpoint_ref' => 'runpod.endpoints.qasr_v2', 'default_language' => 'ckb', 'payload_defaults' => ['type' => 'asr', 'model_variant' => 'fine_tuned', 'intelligent' => 0]], JSON_THROW_ON_ERROR),
                'updated_at' => $now, 'created_at' => $now,
            ]);
            $actionId = (int) DB::table('tool_actions')->where('full_code', 'leo.transcribe')->value('id');
            foreach (DB::table('service_plans')->pluck('id') as $planId) {
                DB::table('plan_entitlements')->updateOrInsert(['service_plan_id' => (int) $planId, 'tool_action_id' => $actionId, 'entitlement_channel' => 'all'], ['allowed' => true, 'limits' => null, 'updated_at' => $now, 'created_at' => $now]);
            }
            $qasrActionId = DB::table('tool_actions')->where('full_code', 'qasr.standard')->value('id');
            $qasrRule = $qasrActionId ? DB::table('pricing_rules')->where('tool_action_id', $qasrActionId)->whereNull('service_plan_id')->orderByDesc('priority')->first() : null;
            $rule = $qasrRule ? (array) $qasrRule : [];
            unset($rule['id'], $rule['tool_action_id'], $rule['created_at'], $rule['updated_at']);
            DB::table('pricing_rules')->updateOrInsert(['tool_action_id' => $actionId, 'service_plan_id' => null, 'pricing_channel' => 'all', 'priority' => 100], array_merge([
                'rule_scope' => 'global', 'rule_type' => 'unit', 'metric_code' => 'minute', 'unit_size' => 1, 'credits_per_unit' => 1000, 'rounding_mode' => 'ceil', 'rounding_step' => 1, 'minimum_credits' => 1000, 'conditions' => null, 'config' => null, 'is_active' => true,
            ], $rule, ['tool_action_id' => $actionId, 'service_plan_id' => null, 'pricing_channel' => 'all', 'priority' => 100, 'updated_at' => $now, 'created_at' => $now]));
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $id = DB::table('tool_actions')->where('full_code', 'leo.transcribe')->value('id');
            if ($id !== null) {
                DB::table('pricing_rules')->where('tool_action_id', $id)->delete();
                DB::table('plan_entitlements')->where('tool_action_id', $id)->delete();
                DB::table('tool_actions')->where('id', $id)->delete();
            }
            DB::table('tools')->where('code', 'leo')->delete();
        });
    }
};

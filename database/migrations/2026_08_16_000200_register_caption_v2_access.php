<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $toolId = DB::table('tools')->where('code', 'caption')->value('id');
            if (! $toolId) {
                DB::table('tools')->insert([
                    'code' => 'caption', 'name' => 'Caption', 'is_active' => true, 'sort_order' => 16,
                    'meta' => json_encode(['category' => 'speech', 'storage_folder' => 'caption', 'runpod_endpoint_ref' => 'runpod.endpoints.qasr', 'worker_type' => 'caption', 'default_language' => 'ckb', 'output_formats' => ['txt', 'srt']], JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('tool_actions')->updateOrInsert(['full_code' => 'caption.standard'], [
                'tool_code' => 'caption', 'action_code' => 'standard', 'name' => 'Caption Standard', 'default_metric_code' => 'minute', 'is_active' => true,
                'meta' => json_encode(['storage_folder' => 'caption', 'worker_type' => 'caption', 'endpoint_ref' => 'runpod.endpoints.qasr', 'default_language' => 'ckb', 'output_formats' => ['txt', 'srt']], JSON_THROW_ON_ERROR),
                'updated_at' => $now, 'created_at' => $now,
            ]);
            $actionId = (int) DB::table('tool_actions')->where('full_code', 'caption.standard')->value('id');
            foreach (DB::table('service_plans')->pluck('id') as $planId) {
                DB::table('plan_entitlements')->updateOrInsert(['service_plan_id' => (int) $planId, 'tool_action_id' => $actionId, 'entitlement_channel' => 'all'], ['allowed' => true, 'limits' => null, 'updated_at' => $now, 'created_at' => $now]);
            }
            $hasPricing = DB::table('pricing_rules')->where('tool_action_id', $actionId)->exists();
            if (! $hasPricing) {
                DB::table('pricing_rules')->insert(['tool_action_id' => $actionId, 'service_plan_id' => null, 'pricing_channel' => 'all', 'priority' => 100, 'rule_scope' => 'global', 'rule_type' => 'unit', 'metric_code' => 'minute', 'unit_size' => 1, 'credits_per_unit' => 1000, 'rounding_mode' => 'ceil', 'rounding_step' => 1, 'minimum_credits' => 1000, 'conditions' => null, 'config' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    public function down(): void
    {
        // Caption existed before V2; its established tool, pricing, and historical jobs are retained.
    }
};

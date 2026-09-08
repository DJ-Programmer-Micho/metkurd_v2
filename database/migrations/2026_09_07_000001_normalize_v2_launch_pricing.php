<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Pretend SELECTs cannot see the preceding registration INSERTs. A dry run
        // cannot validate this data-dependent correction; never invent action IDs.
        if (DB::connection()->pretending()) {
            return;
        }

        // Approved launch policy, independent of historical QASR selection/order.
        // Keep this migration self-contained: future resolver/seed changes must not alter it.
        $policies = [
            'xomni-v2.generate' => ['tool' => 'xomni-v2', 'metric' => 'character', 'minimum' => 1, 'rates' => ['all' => 20, 'app' => 20, 'mobile' => 20, 'api' => 15]],
            'vector-v2.generate' => ['tool' => 'vector-v2', 'metric' => 'character', 'minimum' => 1, 'rates' => ['all' => 24, 'app' => 24, 'mobile' => 24, 'api' => 18]],
            'leo.transcribe' => ['tool' => 'leo', 'metric' => 'minute', 'minimum' => 1100, 'rates' => ['all' => 1100, 'app' => 1100, 'mobile' => 1100, 'api' => 825]],
        ];

        DB::transaction(function () use ($policies): void {
            foreach ($policies as $code => $policy) {
                $actions = DB::table('tool_actions')->where('full_code', $code)->lockForUpdate()->get();
                if ($actions->count() !== 1 || $actions[0]->tool_code !== $policy['tool']
                    || $actions[0]->action_code !== substr($code, strlen($policy['tool']) + 1)
                    || ! DB::table('tools')->where('code', $policy['tool'])->exists()) {
                    throw new RuntimeException('V2 launch pricing requires the registered action and matching tool: '.$code);
                }
                $actionId = $actions[0]->id;
                $global = DB::table('pricing_rules')->where('tool_action_id', $actionId)->whereNull('service_plan_id');
                $retainedIds = [];

                foreach ($policy['rates'] as $channel => $rate) {
                    // MIN selects a stable row identity only; economics always come from the policy above.
                    $id = (clone $global)->where('pricing_channel', $channel)->min('id');
                    $values = [
                        'tool_action_id' => $actionId, 'service_plan_id' => null,
                        'pricing_channel' => $channel, 'priority' => 100,
                        'rule_scope' => 'global', 'rule_type' => 'unit',
                        'metric_code' => $policy['metric'], 'unit_size' => 1,
                        'credits_per_unit' => $rate, 'minimum_credits' => $policy['minimum'],
                        'rounding_mode' => 'ceil', 'rounding_step' => 1,
                        'conditions' => null, 'config' => null,
                        'starts_at' => null, 'ends_at' => null, 'is_active' => true,
                    ];
                    if ($id === null) {
                        $id = DB::table('pricing_rules')->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
                    } elseif (! DB::table('pricing_rules')->where('id', $id)->where($values)->exists()) {
                        DB::table('pricing_rules')->where('id', $id)->update($values + ['updated_at' => now()]);
                    }
                    $retainedIds[] = $id;
                }

                // Retain superseded rows as inactive evidence, never as an active cheap fallback.
                // Plan/customer overrides are deliberately outside this global launch policy.
                (clone $global)->whereNotIn('id', $retainedIds)->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Historical pre-correction prices cannot be reconstructed safely. Retain the
        // approved policy; an intentional later price change needs a forward migration.
    }
};

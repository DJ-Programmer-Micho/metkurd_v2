<?php

namespace Database\Seeders;

use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CaptionToolSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $tool = Tool::updateOrCreate(
                ['code' => 'caption'],
                [
                    'name' => 'Kurdish Caption',
                    'is_active' => true,
                    'sort_order' => 6,
                    'meta' => [
                        'category' => 'speech',
                        'storage_folder' => 'caption',
                        'runpod_endpoint_ref' => 'runpod.endpoints.qasr',
                        'worker_type' => 'caption',
                        'default_language' => 'ckb',
                        'output_formats' => ['txt', 'srt'],
                    ],
                ]
            );

            $action = ToolAction::updateOrCreate(
                ['full_code' => 'caption.standard'],
                [
                    'tool_code' => 'caption',
                    'action_code' => 'standard',
                    'name' => 'Caption Standard',
                    'default_metric_code' => 'minute',
                    'is_active' => true,
                    'meta' => [
                        'storage_folder' => 'caption',
                        'worker_type' => 'caption',
                        'endpoint_ref' => 'runpod.endpoints.qasr',
                        'default_language' => 'ckb',
                        'output_formats' => ['txt', 'srt'],
                    ],
                ]
            );

            ServicePlan::query()
                ->select('id')
                ->orderBy('id')
                ->get()
                ->each(function (ServicePlan $plan) use ($action): void {
                    PlanEntitlement::updateOrCreate(
                        [
                            'service_plan_id' => (int) $plan->id,
                            'tool_action_id' => (int) $action->id,
                            'entitlement_channel' => 'all',
                        ],
                        [
                            'allowed' => true,
                            'limits' => null,
                        ]
                    );
                });

            PricingRule::updateOrCreate(
                [
                    'tool_action_id' => (int) $action->id,
                    'service_plan_id' => null,
                    'pricing_channel' => 'all',
                    'priority' => 100,
                ],
                [
                    'rule_scope' => 'global',
                    'rule_type' => 'unit',
                    'metric_code' => 'minute',
                    'unit_size' => 1,
                    'credits_per_unit' => 1000,
                    'rounding_mode' => 'ceil',
                    'rounding_step' => 1,
                    'minimum_credits' => 1000,
                    'conditions' => null,
                    'config' => null,
                    'is_active' => true,
                ]
            );

            $tool->refresh();
            $action->refresh();
        });
    }
}

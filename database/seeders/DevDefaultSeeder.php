<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\Voice;
use App\Models\PlanVoiceAccess;

class DevDefaultSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            // ------------------------------------------------------------
            // 1) Plans
            // ------------------------------------------------------------
            $plans = $this->seedServicePlans();
            $storagePlans = $this->seedStoragePlans();

            // ------------------------------------------------------------
            // 2) Tools + Actions
            // ------------------------------------------------------------
            [$tools, $actions] = $this->seedToolsAndActions();

            // ------------------------------------------------------------
            // 3) Entitlements (permissions)
            // ------------------------------------------------------------
            $this->seedPlanEntitlements($plans, $actions);

            // ------------------------------------------------------------
            // 4) Pricing rules (credits)
            // ------------------------------------------------------------
            $this->seedPricingRules($actions);

            // ------------------------------------------------------------
            // 5) Voices + voice access per plan
            // ------------------------------------------------------------
            $this->seedVoicesAndAccess($plans);

        });
    }

    private function seedServicePlans(): array
    {
        $rows = [
            [
                'code' => 'free',
                'name' => 'Free',
                'monthly_credits' => 50,
                'is_active' => true,
                'sort_order' => 1,
                'ui_features' => [
                    'badge' => 'FREE',
                ],
            ],
            [
                'code' => 'student',
                'name' => 'Student',
                'monthly_credits' => 2000,
                'is_active' => true,
                'sort_order' => 2,
                'ui_features' => [
                    'badge' => 'STUDENT',
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'monthly_credits' => 5000,
                'is_active' => true,
                'sort_order' => 3,
                'ui_features' => [
                    'badge' => 'PRO',
                ],
            ],
            [
                'code' => 'premium',
                'name' => 'Premium',
                'monthly_credits' => 10000,
                'is_active' => true,
                'sort_order' => 4,
                'ui_features' => [
                    'badge' => 'PREMIUM',
                ],
            ],
        ];

        $out = [];
        foreach ($rows as $r) {
            $out[$r['code']] = ServicePlan::updateOrCreate(
                ['code' => $r['code']],
                $r
            );
        }
        return $out;
    }

    private function seedStoragePlans(): array
    {
        $rows = [
            ['code' => 'free-512',     'name' => 'Free (512MB)',     'quota_mb' => 512,   'is_active' => true, 'sort_order' => 1],
            ['code' => 'student-3072', 'name' => 'Student (3GB)',    'quota_mb' => 3072,  'is_active' => true, 'sort_order' => 2],
            ['code' => 'pro-5120',     'name' => 'Pro (5GB)',        'quota_mb' => 5120,  'is_active' => true, 'sort_order' => 3],
            ['code' => 'premium-10240','name' => 'Premium (10GB)',   'quota_mb' => 10240, 'is_active' => true, 'sort_order' => 4],
        ];

        $out = [];
        foreach ($rows as $r) {
            $out[$r['code']] = StoragePlan::updateOrCreate(
                ['code' => $r['code']],
                $r
            );
        }
        return $out;
    }

    private function seedToolsAndActions(): array
    {
        // Tools
        $toolRows = [
            ['code' => 'xtts',            'name' => 'XTTS Text-to-Speech',          'sort_order' => 1],
            ['code' => 'clone_xtts',      'name' => 'Clone XTTS (Voice Cloning)',  'sort_order' => 2],
            ['code' => 'asr',             'name' => 'ASR Speech-to-Text',          'sort_order' => 3],
            ['code' => 'ocr',             'name' => 'OCR',                         'sort_order' => 4],
            ['code' => 'audio_isolation', 'name' => 'Audio Isolation',             'sort_order' => 5],
            ['code' => 'yt_downloader',   'name' => 'YouTube Downloader',          'sort_order' => 6],
        ];

        $tools = [];
        foreach ($toolRows as $r) {
            $tools[$r['code']] = Tool::updateOrCreate(
                ['code' => $r['code']],
                [
                    'name' => $r['name'],
                    'is_active' => true,
                    'sort_order' => $r['sort_order'],
                    'meta' => null,
                ]
            );
        }

        // Actions
        $actionRows = [
            // XTTS
            ['tool' => 'xtts', 'code' => 'generate', 'name' => 'Generate Speech'],

            // Clone XTTS
            ['tool' => 'clone_xtts', 'code' => 'generate', 'name' => 'Generate Cloned Speech'],

            // ASR
            ['tool' => 'asr', 'code' => 'transcribe', 'name' => 'Transcribe Audio'],

            // OCR
            ['tool' => 'ocr', 'code' => 'extract_text', 'name' => 'Extract Text'],

            // Audio Isolation
            ['tool' => 'audio_isolation', 'code' => 'separate', 'name' => 'Separate Stems'],

            // YouTube
            ['tool' => 'yt_downloader', 'code' => 'download_audio', 'name' => 'Download Audio'],
            ['tool' => 'yt_downloader', 'code' => 'download_video', 'name' => 'Download Video'],
        ];

        $actions = [];
        foreach ($actionRows as $r) {
            $tool = $tools[$r['tool']];

            $fullCode = $r['tool'].'.'.$r['code'];

            $actions[$fullCode] = ToolAction::updateOrCreate(
                ['full_code' => $fullCode],
                [
                    'tool_id' => $tool->id,
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'is_active' => true,
                    'meta' => null,
                ]
            );
        }

        return [$tools, $actions];
    }

    private function seedPlanEntitlements(array $plans, array $actions): void
    {
        // All plans can use all tools, except:
        // - YouTube video: Free denied, Student/Pro/Premium allowed
        // - YouTube audio: allowed for all

        $planCodes = array_keys($plans);

        foreach ($planCodes as $planCode) {
            $plan = $plans[$planCode];

            foreach ($actions as $fullCode => $action) {
                $allowed = true;

                if ($fullCode === 'yt_downloader.download_video') {
                    $allowed = ($planCode !== 'free');
                }

                PlanEntitlement::updateOrCreate(
                    ['service_plan_id' => $plan->id, 'tool_action_id' => $action->id],
                    ['allowed' => $allowed, 'limits' => null]
                );
            }
        }
    }

    private function seedPricingRules(array $actions): void
    {
        // Your default costs:
        // XTTS: 10
        // Clone XTTS: 20
        // ASR: 15
        // OCR: 10
        // Audio Isolation: 10
        // YT audio: 5
        // YT video: 10 BUT free for Pro+Premium

        $fixed = [
            'xtts.generate' => 10,
            'clone_xtts.generate' => 20,
            'asr.transcribe' => 15,
            'ocr.extract_text' => 10,
            'audio_isolation.separate' => 10,
            'yt_downloader.download_audio' => 5,
        ];

        foreach ($fixed as $fullCode => $cost) {
            $action = $actions[$fullCode];

            PricingRule::updateOrCreate(
                [
                    'tool_action_id' => $action->id,
                    'rule_type' => 'fixed',
                    'priority' => 100,
                ],
                [
                    'conditions' => null,
                    'cost_credits' => $cost,
                    'config' => null,
                    'is_active' => true,
                ]
            );
        }

        // YT video: rule 1 => free for Pro/Premium
        $ytVideo = $actions['yt_downloader.download_video'];

        PricingRule::updateOrCreate(
            [
                'tool_action_id' => $ytVideo->id,
                'rule_type' => 'conditional',
                'priority' => 1000,
            ],
            [
                'conditions' => ['plan_codes' => ['pro', 'premium']],
                'cost_credits' => 0,
                'config' => null,
                'is_active' => true,
            ]
        );

        // YT video: fallback fixed 10 credits
        PricingRule::updateOrCreate(
            [
                'tool_action_id' => $ytVideo->id,
                'rule_type' => 'fixed',
                'priority' => 100,
            ],
            [
                'conditions' => null,
                'cost_credits' => 10,
                'config' => null,
                'is_active' => true,
            ]
        );
    }

    private function seedVoicesAndAccess(array $plans): void
    {
        // Example: Free has 4 voices. Paid plans have 20+.
        // You can replace codes/names with your real XTTS voice list.

        $voiceList = [
            // Free 4
            ['code' => 'liza',         'name' => 'Liza',         'is_free' => true],
            ['code' => 'taha_fathi',   'name' => 'Taha Fathi',   'is_free' => true],
            ['code' => 'shwan',        'name' => 'Shwan',        'is_free' => true],
            ['code' => 'johan',        'name' => 'Johan',        'is_free' => true],

            // Extra paid voices (example)
            ['code' => 'serok_barzani','name' => 'Serok Barzani','is_free' => false],
        ];

        $voiceModels = [];
        foreach ($voiceList as $v) {
            $voiceModels[$v['code']] = Voice::updateOrCreate(
                ['code' => $v['code']],
                [
                    'name' => $v['name'],
                    'tool_code' => 'xtts',
                    'model_code' => 'xtts_ckb', // change if you want
                    'is_public' => true,
                    'is_active' => true,
                    'meta' => [
                        'tier' => $v['is_free'] ? 'free' : 'paid',
                    ],
                ]
            );
        }

        // Clear old access mapping (dev seeding convenience)
        PlanVoiceAccess::query()->delete();

        $free = $plans['free'];
        $student = $plans['student'];
        $pro = $plans['pro'];
        $premium = $plans['premium'];

        foreach ($voiceList as $v) {
            $voice = $voiceModels[$v['code']];

            // Free: only 4 voices
            if ($v['is_free']) {
                PlanVoiceAccess::create([
                    'service_plan_id' => $free->id,
                    'voice_id' => $voice->id,
                    'allowed' => true,
                ]);
            }

            // Paid: all voices
            PlanVoiceAccess::create([
                'service_plan_id' => $student->id,
                'voice_id' => $voice->id,
                'allowed' => true,
            ]);

            PlanVoiceAccess::create([
                'service_plan_id' => $pro->id,
                'voice_id' => $voice->id,
                'allowed' => true,
            ]);

            PlanVoiceAccess::create([
                'service_plan_id' => $premium->id,
                'voice_id' => $voice->id,
                'allowed' => true,
            ]);
        }
    }
}
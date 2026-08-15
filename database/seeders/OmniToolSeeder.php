<?php

namespace Database\Seeders;

use App\Models\PlanEntitlement;
use App\Models\PlanVoiceAccess;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\Voice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OmniToolSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $tools = $this->seedTools();
            $actions = $this->seedActions();

            $this->seedEntitlements($actions);
            $this->seedPricingRules($actions);
            $this->seedOmniVoices();

            // Keep references "used" for static analysis and future extension.
            $tools['xomni']->refresh();
            $tools['clone_xomni']->refresh();
        });
    }

    /**
     * @return array<string, Tool>
     */
    protected function seedTools(): array
    {
        $rows = [
            [
                'code' => 'xomni',
                'name' => 'Apollo 1.5v',
                'sort_order' => 11,
                'meta' => [
                    'category' => 'speech',
                    'storage_folder' => 'xomni',
                    'runpod_endpoint_ref' => 'runpod.endpoints.omni',
                    'worker_type' => 'xomni',
                    'default_language' => 'ckb',
                    'languages' => ['ckb', 'en', 'ar'],
                    'output_formats' => ['wav'],
                ],
            ],
            [
                'code' => 'xomni-v2',
                'name' => 'Apollo 2.0v',
                'sort_order' => 12,
                'meta' => [
                    'category' => 'speech',
                    'storage_folder' => 'xomni-v2',
                    'runpod_endpoint_ref' => 'runpod.endpoints.omni',
                    // The external worker contract remains OMNI; this is an internal identity.
                    'worker_type' => 'xomni',
                    'default_language' => 'ckb',
                    'languages' => ['ckb', 'en', 'ar'],
                    'output_formats' => ['wav'],
                ],
            ],
            [
                'code' => 'clone_xomni',
                'name' => 'Vector 1.5v',
                'sort_order' => 13,
                'meta' => [
                    'category' => 'speech',
                    'storage_folder' => 'clone_xomni',
                    'runpod_endpoint_ref' => 'runpod.endpoints.omni',
                    'worker_type' => 'clone_xomni',
                    'default_language' => 'ckb',
                    'languages' => ['ckb', 'en', 'ar'],
                    'output_formats' => ['wav'],
                ],
            ],
        ];

        $tools = [];

        foreach ($rows as $row) {
            $tools[$row['code']] = Tool::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                    'meta' => $row['meta'],
                ]
            );
        }

        return $tools;
    }

    /**
     * @return array<string, ToolAction>
     */
    protected function seedActions(): array
    {
        $rows = [
            [
                'tool_code' => 'xomni',
                'action_code' => 'generate',
                'name' => 'Apollo 1.5v Generate',
                'default_metric_code' => 'character',
                'meta' => [
                    'storage_folder' => 'xomni',
                    'worker_type' => 'xomni',
                    'endpoint_ref' => 'runpod.endpoints.omni',
                    'default_language' => 'ckb',
                    'languages' => ['ckb', 'en', 'ar'],
                    'output_formats' => ['wav'],
                    'payload_defaults' => [
                        'mode' => 'builtin_ref',
                        'output_format' => 'wav',
                        'return_base64' => true,
                        'ref_text' => '',
                    ],
                ],
            ],
            [
                'tool_code' => 'xomni-v2',
                'action_code' => 'generate',
                'name' => 'Apollo 2.0v Generate',
                'default_metric_code' => 'character',
                'meta' => [
                    'storage_folder' => 'xomni-v2',
                    'worker_type' => 'xomni',
                    'endpoint_ref' => 'runpod.endpoints.omni',
                    'default_language' => 'ckb',
                    'languages' => ['ckb', 'en', 'ar'],
                    'output_formats' => ['wav'],
                    'payload_defaults' => [
                        'mode' => 'builtin_ref', 'output_format' => 'wav', 'return_base64' => true, 'ref_text' => '',
                    ],
                ],
            ],
            [
                'tool_code' => 'clone_xomni',
                'action_code' => 'generate',
                'name' => 'Vector 1.5v Generate',
                'default_metric_code' => 'character',
                'meta' => [
                    'storage_folder' => 'clone_xomni',
                    'worker_type' => 'clone_xomni',
                    'endpoint_ref' => 'runpod.endpoints.omni',
                    'default_language' => 'ckb',
                    'languages' => ['ckb', 'en', 'ar'],
                    'output_formats' => ['wav'],
                    'payload_defaults' => [
                        'mode' => 'audio_url',
                        'output_format' => 'wav',
                        'return_base64' => true,
                        'ref_text' => '',
                        'ref_max_sec' => 20,
                    ],
                ],
            ],
        ];

        $actions = [];

        foreach ($rows as $row) {
            $fullCode = $row['tool_code'].'.'.$row['action_code'];

            $actions[$fullCode] = ToolAction::updateOrCreate(
                ['full_code' => $fullCode],
                [
                    'tool_code' => $row['tool_code'],
                    'action_code' => $row['action_code'],
                    'name' => $row['name'],
                    'default_metric_code' => $row['default_metric_code'],
                    'is_active' => true,
                    'meta' => $row['meta'],
                ]
            );
        }

        return $actions;
    }

    /**
     * @param  array<string, ToolAction>  $actions
     */
    protected function seedEntitlements(array $actions): void
    {
        $plans = ServicePlan::query()
            ->select('id')
            ->orderBy('id')
            ->get();

        foreach ($plans as $plan) {
            foreach ($actions as $action) {
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
            }
        }
    }

    /**
     * @param  array<string, ToolAction>  $actions
     */
    protected function seedPricingRules(array $actions): void
    {
        $pricing = [
            'xomni.generate' => 1.0,
            'xomni-v2.generate' => 1.0,
            'clone_xomni.generate' => 1.2,
        ];

        foreach ($pricing as $fullCode => $creditsPerUnit) {
            $action = $actions[$fullCode] ?? null;

            if (! $action) {
                continue;
            }

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
                    'metric_code' => 'character',
                    'unit_size' => 1,
                    'credits_per_unit' => $creditsPerUnit,
                    'rounding_mode' => 'ceil',
                    'rounding_step' => 1,
                    'minimum_credits' => 1,
                    'conditions' => null,
                    'config' => null,
                    'is_active' => true,
                ]
            );
        }
    }

    protected function seedOmniVoices(): void
    {
        $groupDefinitions = $this->omniVoiceGroupDefinitions();
        $previewAudioRelativeSet = $this->omniFinalPreviewAudioRelativeSet();
        $previewImageRelativeSet = $this->omniFinalPreviewImageRelativeSet();

        $voiceRows = [];

        foreach ($groupDefinitions as $groupKey => $definition) {
            $voiceRows = array_merge(
                $voiceRows,
                $this->buildOmniVoiceRowsForGroup(
                    $groupKey,
                    $definition,
                    $previewAudioRelativeSet,
                    $previewImageRelativeSet
                )
            );
        }

        $expectedRefAudios = collect($voiceRows)
            ->map(fn (array $row): string => trim((string) data_get($row, 'meta.ref_audio', '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $voices = [];

        foreach ($voiceRows as $row) {
            $voices[] = Voice::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'is_public' => (bool) $row['is_public'],
                    'is_active' => (bool) $row['is_active'],
                    'sort_order' => (int) $row['sort_order'],
                    'meta' => $row['meta'] ?? null,
                ]
            );
        }

        $this->deactivateStaleOmniVoices($expectedRefAudios);

        $plans = ServicePlan::query()
            ->select('id')
            ->orderBy('id')
            ->get();

        foreach ($plans as $plan) {
            foreach ($voices as $voice) {
                PlanVoiceAccess::updateOrCreate(
                    [
                        'service_plan_id' => (int) $plan->id,
                        'voice_id' => (int) $voice->id,
                    ],
                    [
                        'is_public' => true,
                        'is_active' => true,
                        'sort_order' => (int) ($voice->sort_order ?? 0),
                        'meta' => null,
                    ]
                );
            }
        }
    }

    /**
     * @param  array<int,string>  $expectedRefAudios
     */
    protected function deactivateStaleOmniVoices(array $expectedRefAudios): void
    {
        $expectedLookup = [];

        foreach ($expectedRefAudios as $refAudio) {
            $normalized = trim((string) $refAudio);

            if ($normalized !== '') {
                $expectedLookup[$normalized] = true;
            }
        }

        $staleVoiceIds = [];

        Voice::query()
            ->where('meta->engine', 'xomni')
            ->get(['id', 'is_active', 'is_public', 'meta'])
            ->each(function (Voice $voice) use ($expectedLookup, &$staleVoiceIds): void {
                $meta = (array) ($voice->meta ?? []);
                $refAudio = trim((string) data_get($meta, 'ref_audio', data_get($meta, 'runpod_ref_audio', '')));

                if ($refAudio !== '' && isset($expectedLookup[$refAudio])) {
                    return;
                }

                $meta['preview_available'] = false;
                $meta['deprecated'] = true;
                $meta['deprecated_reason'] = 'not_in_final_omni_catalog';

                $voice->forceFill([
                    'is_active' => false,
                    'is_public' => false,
                    'meta' => $meta,
                ])->save();

                $staleVoiceIds[] = (int) $voice->id;
            });

        if ($staleVoiceIds !== []) {
            PlanVoiceAccess::query()
                ->whereIn('voice_id', $staleVoiceIds)
                ->update([
                    'is_active' => false,
                    'is_public' => false,
                ]);
        }
    }

    /**
     * @return array<string, array{label:string,gender:string,source_name:string,sort_base:int,files:array<int,string>}>
     */
    protected function omniVoiceGroupDefinitions(): array
    {
        return [
            'male_1' => [
                'label' => 'Male 1',
                'gender' => 'male',
                'source_name' => 'hyder',
                'sort_base' => 100,
                'files' => [
                    'hyder_male_angry_1.wav',
                    'hyder_male_angry_2.wav',
                    'hyder_male_confident_1.wav',
                    'hyder_male_confident_2.wav',
                    'hyder_male_cry_1.wav',
                    'hyder_male_excited_1.wav',
                    'hyder_male_fear_1.wav',
                    'hyder_male_fear_2.wav',
                    'hyder_male_fear_3.wav',
                    'hyder_male_happy_1.wav',
                    'hyder_male_happy_2.wav',
                    'hyder_male_nurtral_1.wav',
                    'hyder_male_playful_1.wav',
                    'hyder_male_proud_1.wav',
                    'hyder_male_proud_2.wav',
                    'hyder_male_sad_1.wav',
                    'hyder_male_sarcasm_1.wav',
                    'hyder_male_serious_1.wav',
                    'hyder_male_surprise_1.wav',
                    'hyder_male_surprise_2.wav',
                    'hyder_male_tired_1.wav',
                    'hyder_male_tired_2.wav',
                    'hyder_male_whisper_1.wav',
                    'hyder_male_whisper_2.wav',
                    'hyder_male_whisper_3.wav',
                ],
            ],
            'male_2' => [
                'label' => 'Male 2',
                'gender' => 'male',
                'source_name' => 'shabo',
                'sort_base' => 200,
                'files' => [
                    'shabo_male_angry_1.wav',
                    'shabo_male_angry_2.wav',
                    'shabo_male_calm_1.wav',
                    'shabo_male_confident_1.wav',
                    'shabo_male_cry_1.wav',
                    'shabo_male_excited_1.wav',
                    'shabo_male_fear_1.wav',
                    'shabo_male_fear_2.wav',
                    'shabo_male_happy_1.wav',
                    'shabo_male_neutral_1.wav',
                    'shabo_male_playful_1.wav',
                    'shabo_male_proud_1.wav',
                    'shabo_male_sad_1.wav',
                    'shabo_male_seriuos_1.wav',
                    'shabo_male_surprise_1.wav',
                    'shabo_male_surprise_2.wav',
                    'shabo_male_tired_2.wav',
                    'shabo_male_whisper_1.wav',
                    'shabo_male_whisper_2.wav',
                ],
            ],
            'male_3' => [
                'label' => 'Male 3',
                'gender' => 'male',
                'source_name' => 'marcel',
                'sort_base' => 300,
                'files' => [
                    'marcel_male_angry_1.wav',
                    'marcel_male_calm_1.wav',
                    'marcel_male_confident_1.wav',
                    'marcel_male_cry_1.wav',
                    'marcel_male_excited_1.wav',
                    'marcel_male_fear_1.wav',
                    'marcel_male_fear_2.wav',
                    'marcel_male_happy_1.wav',
                    'marcel_male_happy_2.wav',
                    'marcel_male_neutral_1.wav',
                    'marcel_male_neutral_2.wav',
                    'marcel_male_playful_1.wav',
                    'marcel_male_proud_1.wav',
                    'marcel_male_sad_1.wav',
                    'marcel_male_serious_1.wav',
                    'marcel_male_serious_2.wav',
                    'marcel_male_surprise_1.wav',
                    'marcel_male_tired_1.wav',
                    'marcel_male_whisper_1.wav',
                    'marcel_male_whisper_2.wav',
                ],
            ],
            'female_1' => [
                'label' => 'Female 1',
                'gender' => 'female',
                'source_name' => 'patty',
                'sort_base' => 400,
                'files' => [
                    'patty_female_angry_1.wav',
                    'patty_female_angry_2.wav',
                    'patty_female_calm_1.wav',
                    'patty_female_calm_2.wav',
                    'patty_female_confidence_1.wav',
                    'patty_female_confidence_2.wav',
                    'patty_female_cry_1.wav',
                    'patty_female_fear_1.wav',
                    'patty_female_happy_1.wav',
                    'patty_female_happy_2.wav',
                    'patty_female_playful_1.wav',
                    'patty_female_proud_1.wav',
                    'patty_female_proud_2.wav',
                    'patty_female_sad_1.wav',
                    'patty_female_serious_1.wav',
                    'patty_female_surprise_1.wav',
                    'patty_female_surprise_2.wav',
                    'patty_female_tired_2.wav',
                    'patty_female_whisper_1.wav',
                ],
            ],
            'female_2' => [
                'label' => 'Female 2',
                'gender' => 'female',
                'source_name' => 'liza',
                'sort_base' => 500,
                'files' => [
                    'liza_female_angry_1.wav',
                    'liza_female_calm_1.wav',
                    'liza_female_cry_1.wav',
                    'liza_female_excited_1.wav',
                    'liza_female_fear_1.wav',
                    'liza_female_fear_2.wav',
                    'liza_female_happy_1.wav',
                    'liza_female_nuetral_1.wav',
                    'liza_female_playful_2.wav',
                    'liza_female_proud_1.wav',
                    'liza_female_sad_1.wav',
                    'liza_female_seriuos_1.wav',
                    'liza_female_surprise_1.wav',
                    'liza_female_tired_1.wav',
                    'liza_female_tired_2.wav',
                    'liza_female_whisper_1.wav',
                    'liza_female_whisper_2.wav',
                    'liza_female_whisper_3.wav',
                ],
            ],
            'female_3' => [
                'label' => 'Female 3',
                'gender' => 'female',
                'source_name' => 'bebe',
                'sort_base' => 600,
                'files' => [
                    'bebe_female_angry_1.wav',
                    'bebe_female_angry_2.wav',
                    'bebe_female_calm_1.wav',
                    'bebe_female_confident_1.wav',
                    'bebe_female_crazy_1.wav',
                    'bebe_female_crazy_2.wav',
                    'bebe_female_cry_1.wav',
                    'bebe_female_excited_1.wav',
                    'bebe_female_fear_1.wav',
                    'bebe_female_fear_2.wav',
                    'bebe_female_happy_1.wav',
                    'bebe_female_happy_2.wav',
                    'bebe_female_proud_1.wav',
                    'bebe_female_sad_1.wav',
                    'bebe_female_sad_2.wav',
                    'bebe_female_serious_1.wav',
                    'bebe_female_surprise_1.wav',
                    'bebe_female_tired_1.wav',
                    'bebe_female_whisper_1.wav',
                    'bebe_female_whisper_2.wav',
                ],
            ],
            'custom' => [
                'label' => 'Custom',
                'gender' => 'custom',
                'source_name' => 'custom',
                'sort_base' => 700,
                'files' => [
                    'female_advertiser_01.wav',
                    'female_angry_01.wav',
                    'female_mad_01.wav',
                    'female_sad_01.wav',
                    'female_whisper_01.wav',
                    'male_angry_01.wav',
                    'male_cry_01.wav',
                    'male_guiding_01.wav',
                    'male_happy_01.wav',
                    'male_pointing_01.wav',
                    'male_sarcasim_01.wav',
                    'male_sarcasim_02.wav',
                    'male_seriuos_01.wav',
                    'male_suspect_01.wav',
                    'male_whisper_01.wav',
                    'male_whisper_02.wav',
                    'male_whisper_03.wav',
                ],
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function omniFinalPreviewAudioRelativeSet(): array
    {
        $set = [];

        foreach ($this->omniVoiceGroupDefinitions() as $groupKey => $definition) {
            foreach ((array) data_get($definition, 'files', []) as $filename) {
                $path = trim($groupKey.'/'.trim((string) $filename), '/');

                if ($path !== '') {
                    $set[$path] = true;
                }
            }
        }

        return $set;
    }

    /**
     * @return array<string, bool>
     */
    protected function omniFinalPreviewImageRelativeSet(): array
    {
        $set = [];

        foreach ([
            'custom/female_advertiser_01.jpg',
            'custom/female_angry_01.jpg',
            'custom/female_mad_01.jpg',
            'custom/female_sad_01.jpg',
            'custom/female_whisper_01.jpg',
            'custom/male_angry_01.jpg',
            'custom/male_cry_01.jpg',
            'custom/male_guiding_01.jpg',
            'custom/male_happy_01.jpg',
            'custom/male_pointing_01.jpg',
            'custom/male_sarcasim_01.jpg',
            'custom/male_sarcasim_02.jpg',
            'custom/male_seriuos_01.jpg',
            'custom/male_suspect_01.jpg',
            'custom/male_whisper_01.jpg',
            'custom/male_whisper_02.jpg',
            'custom/male_whisper_03.jpg',
            'female_1/patty_female_angry.jpg',
            'female_1/patty_female_calm.jpg',
            'female_1/patty_female_confidence.jpg',
            'female_1/patty_female_cry.jpg',
            'female_1/patty_female_excited.jpg',
            'female_1/patty_female_fear.jpg',
            'female_1/patty_female_happy.jpg',
            'female_1/patty_female_playful.jpg',
            'female_1/patty_female_proud.jpg',
            'female_1/patty_female_sad.jpg',
            'female_1/patty_female_sarcasm.jpg',
            'female_1/patty_female_serious.jpg',
            'female_1/patty_female_surprise.jpg',
            'female_1/patty_female_tired.jpg',
            'female_1/patty_female_whisper.jpg',
            'female_2/liza_female_angry.jpg',
            'female_2/liza_female_calm.jpg',
            'female_2/liza_female_confident.jpg',
            'female_2/liza_female_cry.jpg',
            'female_2/liza_female_excited.jpg',
            'female_2/liza_female_fear.jpg',
            'female_2/liza_female_happy.jpg',
            'female_2/liza_female_neutral.jpg',
            'female_2/liza_female_playful.jpg',
            'female_2/liza_female_proud.jpg',
            'female_2/liza_female_sad.jpg',
            'female_2/liza_female_sarcasm.jpg',
            'female_2/liza_female_seriuos.jpg',
            'female_2/liza_female_surprise.jpg',
            'female_2/liza_female_tired.jpg',
            'female_2/liza_female_whisper.jpg',
            'female_3/bebe_female_angry.jpg',
            'female_3/bebe_female_calm.jpg',
            'female_3/bebe_female_confident.jpg',
            'female_3/bebe_female_crazy.jpg',
            'female_3/bebe_female_cry.jpg',
            'female_3/bebe_female_excited.jpg',
            'female_3/bebe_female_fear.jpg',
            'female_3/bebe_female_happy.jpg',
            'female_3/bebe_female_proud.jpg',
            'female_3/bebe_female_sad.jpg',
            'female_3/bebe_female_sarcasm.jpg',
            'female_3/bebe_female_serious.jpg',
            'female_3/bebe_female_surprise.jpg',
            'female_3/bebe_female_tired.jpg',
            'female_3/bebe_female_whisper.jpg',
            'male_1/hyder_male_angry.jpg',
            'male_1/hyder_male_confident.jpg',
            'male_1/hyder_male_cry.jpg',
            'male_1/hyder_male_excited.jpg',
            'male_1/hyder_male_fear.jpg',
            'male_1/hyder_male_happy.jpg',
            'male_1/hyder_male_nuetral.jpg',
            'male_1/hyder_male_playful.jpg',
            'male_1/hyder_male_proud.jpg',
            'male_1/hyder_male_sad.jpg',
            'male_1/hyder_male_sarcasm.jpg',
            'male_1/hyder_male_serious.jpg',
            'male_1/hyder_male_surprise.jpg',
            'male_1/hyder_male_tired.jpg',
            'male_1/hyder_male_whisper.jpg',
            'male_2/shabo_male_angry.jpg',
            'male_2/shabo_male_calm.jpg',
            'male_2/shabo_male_confident.jpg',
            'male_2/shabo_male_cry.jpg',
            'male_2/shabo_male_excited.jpg',
            'male_2/shabo_male_fear.jpg',
            'male_2/shabo_male_happy.jpg',
            'male_2/shabo_male_neutral.jpg',
            'male_2/shabo_male_playful.jpg',
            'male_2/shabo_male_proud.jpg',
            'male_2/shabo_male_sad.jpg',
            'male_2/shabo_male_sarcasm.jpg',
            'male_2/shabo_male_seriuos.jpg',
            'male_2/shabo_male_surprise.jpg',
            'male_2/shabo_male_tired.jpg',
            'male_2/shabo_male_whisper.jpg',
            'male_3/marcel_male_angry.jpg',
            'male_3/marcel_male_calm.jpg',
            'male_3/marcel_male_confident.jpg',
            'male_3/marcel_male_cry.jpg',
            'male_3/marcel_male_excited.jpg',
            'male_3/marcel_male_fear.jpg',
            'male_3/marcel_male_happy.jpg',
            'male_3/marcel_male_neutral.jpg',
            'male_3/marcel_male_playful.jpg',
            'male_3/marcel_male_proud.jpg',
            'male_3/marcel_male_sad.jpg',
            'male_3/marcel_male_sarcasim.jpg',
            'male_3/marcel_male_serious.jpg',
            'male_3/marcel_male_surprise.jpg',
            'male_3/marcel_male_tired.jpg',
            'male_3/marcel_male_whisper.jpg',
        ] as $relativePath) {
            $path = trim((string) $relativePath);

            if ($path !== '') {
                $set[$path] = true;
            }
        }

        return $set;
    }

    /**
     * @param  array{label:string,gender:string,source_name:string,sort_base:int,files:array<int,string>}  $definition
     * @param  array<string,bool>  $previewAudioRelativeSet
     * @param  array<string,bool>  $previewImageRelativeSet
     * @return array<int, array<string,mixed>>
     */
    protected function buildOmniVoiceRowsForGroup(
        string $groupKey,
        array $definition,
        array $previewAudioRelativeSet,
        array $previewImageRelativeSet
    ): array {
        $rows = [];
        $label = (string) ($definition['label'] ?? Str::headline(str_replace('_', ' ', $groupKey)));
        $gender = (string) ($definition['gender'] ?? 'custom');
        $sourceName = (string) ($definition['source_name'] ?? $groupKey);
        $sortBase = (int) ($definition['sort_base'] ?? 0);
        $files = array_values((array) ($definition['files'] ?? []));
        $usedCodes = [];

        foreach ($files as $index => $filename) {
            $filename = trim((string) $filename);

            if ($filename === '') {
                continue;
            }

            $runpodRefAudio = $groupKey.'/'.$filename;
            $hasPreview = (bool) ($previewAudioRelativeSet[$runpodRefAudio] ?? false);
            $previewAudioPath = $hasPreview
                ? 'metkurd_audio_data/omni/'.$runpodRefAudio
                : null;
            $avatarPath = $this->resolveOmniAvatarPath($runpodRefAudio, $previewImageRelativeSet);
            $variant = $this->resolveOmniVariantFromFilename($filename);
            $style = $this->resolveOmniStyleFromFilename($filename);
            $displayName = $this->buildOmniDisplayName($groupKey, $label, $filename, $style, $variant);

            $stem = Str::of(pathinfo($filename, PATHINFO_FILENAME))
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->value();

            $codeBase = 'xomni_'.$groupKey.'_'.($stem !== '' ? $stem : (string) ($index + 1));
            $code = $codeBase;
            $codeSuffix = 2;

            while (isset($usedCodes[$code])) {
                $code = $codeBase.'_'.$codeSuffix;
                $codeSuffix++;
            }

            $usedCodes[$code] = true;

            $rows[] = [
                'code' => $code,
                'name' => $displayName,
                'is_public' => true,
                'is_active' => $runpodRefAudio !== '' && $hasPreview,
                'sort_order' => $sortBase + $index,
                'meta' => [
                    'engine' => 'xomni',
                    'gender' => $gender,
                    'speaker_group' => $groupKey,
                    'speaker_label' => $label,
                    'speaker_source_name' => $sourceName,
                    'style' => $style,
                    'variant' => $variant,
                    'display_name' => $displayName,
                    'ref_audio' => $runpodRefAudio,
                    'runpod_ref_audio' => $runpodRefAudio,
                    'preview_audio' => $previewAudioPath,
                    'preview_audio_path' => $previewAudioPath,
                    'preview_image_path' => $avatarPath,
                    'avatar' => $avatarPath,
                    'avatar_path' => $avatarPath,
                    'preview_available' => $hasPreview,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,bool>  $previewImageRelativeSet
     */
    protected function resolveOmniAvatarPath(string $runpodRefAudio, array $previewImageRelativeSet): ?string
    {
        $relative = trim(str_replace('\\', '/', $runpodRefAudio), '/');

        if ($relative === '') {
            return null;
        }

        $directory = trim(str_replace('\\', '/', dirname($relative)), '/.');
        $stem = (string) pathinfo($relative, PATHINFO_FILENAME);

        if ($directory === '' || $stem === '') {
            return null;
        }

        $candidates = [$directory.'/'.$stem.'.jpg'];

        if (preg_match('/^(.*)_\d+$/u', $stem, $matches) === 1) {
            $baseStem = trim((string) ($matches[1] ?? ''));

            if ($baseStem !== '') {
                $candidates[] = $directory.'/'.$baseStem.'.jpg';
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $normalized = trim(str_replace('\\', '/', (string) $candidate), '/');

            if ($normalized !== '' && isset($previewImageRelativeSet[$normalized])) {
                return 'metkurd_audio_data/omni/'.$normalized;
            }
        }

        return null;
    }

    protected function resolveOmniStyleFromFilename(string $filename): string
    {
        $stem = (string) Str::of(pathinfo($filename, PATHINFO_FILENAME))
            ->replace('\\', '/')
            ->trim()
            ->value();

        if (preg_match('/^(?:[^_]+_)?(?:male|female)[ _]+(.+?)_(\d+)$/iu', $stem, $matches) === 1) {
            $style = trim((string) ($matches[1] ?? ''));

            if ($style !== '') {
                return Str::of($style)
                    ->lower()
                    ->replaceMatches('/[^a-z0-9]+/', '_')
                    ->trim('_')
                    ->value();
            }
        }

        if (preg_match('/^(.+?)_(\d+)$/u', $stem, $matches) === 1) {
            $style = trim((string) ($matches[1] ?? ''));

            if ($style !== '') {
                return Str::of($style)
                    ->lower()
                    ->replaceMatches('/[^a-z0-9]+/', '_')
                    ->trim('_')
                    ->value();
            }
        }

        return 'voice';
    }

    protected function resolveOmniVariantFromFilename(string $filename): int
    {
        $stem = (string) Str::of(pathinfo($filename, PATHINFO_FILENAME))
            ->replace('\\', '/')
            ->trim()
            ->value();

        if (preg_match('/_(\d+)$/u', $stem, $matches) !== 1) {
            return 1;
        }

        $variant = (int) ($matches[1] ?? 1);

        return $variant > 0 ? $variant : 1;
    }

    protected function buildOmniDisplayName(
        string $groupKey,
        string $groupLabel,
        string $filename,
        string $style,
        int $variant
    ): string {
        $styleLabel = Str::of($style)
            ->replace('_', ' ')
            ->squish()
            ->headline()
            ->value();

        if ($styleLabel === '') {
            $styleLabel = 'Voice';
        }

        if ($groupKey === 'custom') {
            $prefix = Str::startsWith(Str::lower($filename), ['female_', 'female '])
                ? 'Female'
                : (Str::startsWith(Str::lower($filename), ['male_', 'male ']) ? 'Male' : $groupLabel);

            return trim(sprintf('%s %s %02d', $prefix, $styleLabel, max(1, $variant)));
        }

        return trim(sprintf('%s %s %d', $groupLabel, $styleLabel, max(1, $variant)));
    }
}

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Staged dashboard rollout
    |--------------------------------------------------------------------------
    |
    | Keep this false in production until V2 submission flows have passed the
    | end-to-end credit, storage, and provider acceptance checks. The routes
    | remain registered so cached route deployments do not need a rebuild.
    |
    */
    'enabled' => (bool) env('FEATURE_APP_V2', false),
    'ocr_pdfinfo_binary' => env('OCR_PDFINFO_BINARY', 'pdfinfo'),

    'cache' => [
        // Voice artwork/reference metadata is shared per plan and locale; jobs remain canonical.
        'speaker_catalog_ttl_seconds' => (int) env('METKURD_V2_SPEAKER_CATALOG_TTL', 600),
        'ctts_reference_ttl_seconds' => (int) env('METKURD_V2_CTTS_REFERENCE_TTL', 600),
        'ctts_completed_render_ttl_seconds' => (int) env('METKURD_V2_CTTS_RENDER_TTL', 20),
        'leo_completed_render_ttl_seconds' => (int) env('METKURD_V2_LEO_RENDER_TTL', 20),
        'caption_completed_render_ttl_seconds' => (int) env('METKURD_V2_CAPTION_RENDER_TTL', 20),
    ],

    // Presentation only: MlJob remains the source of truth for these states.
    'job_statuses' => [
        'queued' => ['label' => 'Queued', 'semantic' => 'warning', 'glass_class' => 'glass-load--warning'],
        'submitting' => ['label' => 'Queued', 'semantic' => 'warning', 'glass_class' => 'glass-load--warning'],
        'running' => ['label' => 'Running', 'semantic' => 'info', 'glass_class' => 'glass-load--info'],
        'saving' => ['label' => 'Saving', 'semantic' => 'primary', 'glass_class' => 'glass-load--primary'],
        'done' => ['label' => 'Done', 'semantic' => 'success', 'glass_class' => 'glass-load--success'],
        'failed' => ['label' => 'Failed', 'semantic' => 'danger', 'glass_class' => 'glass-load--danger'],
        'cancelled' => ['label' => 'Cancelled', 'semantic' => 'secondary', 'glass_class' => 'glass-load--secondary'],
        'canceled' => ['label' => 'Cancelled', 'semantic' => 'secondary', 'glass_class' => 'glass-load--secondary'],
        'deleting' => ['label' => 'Deleting', 'semantic' => 'warning', 'glass_class' => 'glass-load--warning'],
        'deleted' => ['label' => 'Deleted', 'semantic' => 'secondary', 'glass_class' => 'glass-load--secondary'],
        'delete_failed' => ['label' => 'Deletion failed', 'semantic' => 'danger', 'glass_class' => 'glass-load--danger'],
        'idle' => ['label' => 'Idle', 'semantic' => 'secondary', 'glass_class' => 'glass-load--secondary'],
    ],

    /*
    |--------------------------------------------------------------------------
    | V2 navigation and provider contracts
    |--------------------------------------------------------------------------
    |
    | These are presentation/contract definitions, not database identifiers.
    | Existing Tool and ToolAction rows remain the pricing and entitlement
    | authority during the transition.
    |
    */
    'services' => [
        'text-to-speech' => [
            'name' => 'Text-to-Speech',
            'description' => 'Turn scripts into natural, expressive speech.',
            'icon_asset' => 'app/services_icons/TTS.png',
            'color' => 'primary',
            'tools' => [
                'apollo-1' => [
                    'name' => 'Apollo 1.5v',
                    'legacy_tool' => 'xomni',
                    'legacy_action' => 'xomni.generate',
                    'access' => 'xomni.generate',
                    'endpoint' => 'omni_v2',
                    'provider_model' => 'model_1',
                    'kind' => 'omni_tts',
                ],
                'apollo-2' => [
                    'name' => 'Apollo 2.0v',
                    'legacy_tool' => 'xomni-v2',
                    'legacy_action' => 'xomni-v2.generate',
                    'access' => 'xomni-v2.generate',
                    'endpoint' => 'omni_v2',
                    'provider_model' => 'model_2',
                    'kind' => 'omni_tts',
                ],
                'multi-speaker-1' => [
                    'name' => 'Multi Speaker 1.0v',
                    'coming_soon' => true,
                ],
            ],
        ],
        'clone-text-to-speech' => [
            'name' => 'Clone Text-to-Speech',
            'description' => 'Create speech using a custom voice reference.',
            'icon_asset' => 'app/services_icons/CTTS.png',
            'color' => 'danger',
            'tools' => [
                'vector-1' => [
                    'name' => 'Vector 1.5v',
                    'legacy_tool' => 'clone_xomni',
                    'legacy_action' => 'clone_xomni.generate',
                    'access' => 'clone_xomni.generate',
                    'endpoint' => 'omni_v2',
                    'provider_model' => 'model_1',
                    'kind' => 'omni_clone',
                ],
                'vector-2' => [
                    'name' => 'Vector 2.0v',
                    'legacy_tool' => 'vector-v2',
                    'legacy_action' => 'vector-v2.generate',
                    'access' => 'vector-v2.generate',
                    'endpoint' => 'omni_v2',
                    'provider_model' => 'model_2',
                    'kind' => 'omni_clone',
                ],
            ],
        ],
        'speech-to-text' => [
            'name' => 'Speech-to-Text',
            'description' => 'Transcribe audio into searchable, exportable text.',
            'icon_asset' => 'app/services_icons/ASR.png',
            'color' => 'success',
            'tools' => [
                'leo' => [
                    'name' => 'Leo',
                    'legacy_tool' => 'leo',
                    'legacy_action' => 'leo.transcribe',
                    'access' => 'leo.transcribe',
                    'endpoint' => 'qasr_v2',
                    'kind' => 'qasr',
                ],
                'caption' => [
                    'name' => 'Caption',
                    'legacy_tool' => 'caption',
                    'legacy_action' => 'caption.standard',
                    'access' => 'caption.standard',
                    'endpoint' => 'qasr_v2',
                    'kind' => 'caption',
                ],
                'caption-edit' => [
                    'name' => 'Caption + Edit',
                    'coming_soon' => true,
                ],
            ],
        ],
        'ocr' => [
            'name' => 'OCR',
            'description' => 'Extract text from scans, images, and documents.',
            'icon_asset' => 'app/services_icons/OCR.png',
            'color' => 'info',
            'tools' => [
                'scanner' => [
                    'name' => 'OCR Scanner 2.0',
                    'legacy_tool' => 'ocr',
                    'legacy_action' => 'ocr.standard',
                    'access' => 'ocr.standard',
                    'endpoint' => 'kocr_v2',
                    'kind' => 'kocr',
                ],
            ],
        ],
        'stem' => [
            'name' => 'STEM',
            'description' => 'Separate vocals and instruments into usable tracks.',
            'icon_asset' => 'app/services_icons/STEM.png',
            'color' => 'warning',
            'tools' => [
                '2-stem' => [
                    'name' => '2 Separation',
                    'legacy_tool' => 'stem',
                    'legacy_action' => 'stem.sep2',
                    'access' => 'stem',
                    'endpoint' => 'stem',
                    'kind' => 'stem',
                    'stems' => 2,
                ],
                '4-stem' => [
                    'name' => '4 Separation',
                    'legacy_tool' => 'stem',
                    'legacy_action' => 'stem.sep4',
                    'access' => 'stem',
                    'endpoint' => 'stem',
                    'kind' => 'stem',
                    'stems' => 4,
                ],
                'create-ads' => [
                    'name' => 'Create Ads',
                    'coming_soon' => true,
                ],
            ],
        ],
    ],
];

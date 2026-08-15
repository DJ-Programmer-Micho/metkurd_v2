<?php

return [
    'base_url' => env('RUNPOD_BASE_URL', 'https://api.runpod.ai'),
    'api_key' => env('RUNPOD_API_KEY'),

    // fallback endpoints (if tool.meta not set)
    'endpoints' => [
        'xtts' => env('RUNPOD_ENDPOINT_ID_XTTS'),
        'omni' => env('RUNPOD_ENDPOINT_ID_OMNI'),
        'ftts' => env('RUNPOD_ENDPOINT_ID_FTTS'),
        'wasr' => env('RUNPOD_ENDPOINT_ID_WASR'),
        'qasr' => env('RUNPOD_ENDPOINT_ID_QASR'),
        'tran' => env('RUNPOD_ENDPOINT_ID_TRAN'),
        'stem' => env('RUNPOD_ENDPOINT_ID_STEM'),
        'kocr' => env('RUNPOD_ENDPOINT_ID_KOCR'),

        // V2 endpoints are deliberately separate from the legacy values above.
        // Do not repoint a V1 tool at these during the staged dashboard rollout.
        'omni_v2' => env('RUNPOD_ENDPOINT_ID_OMNI_V2'),
        'qasr_v2' => env('RUNPOD_ENDPOINT_ID_QASR_V2'),
        'kocr_v2' => env('RUNPOD_ENDPOINT_ID_KOCR_V2'),
    ],

    'timeout' => (int) env('RUNPOD_TIMEOUT', 60),
    'v2_timeout' => (int) env('RUNPOD_V2_TIMEOUT', env('RUNPOD_TIMEOUT', 60)),
    'v2_input_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('RUNPOD_V2_INPUT_HOSTS', ''))
    ))),
];

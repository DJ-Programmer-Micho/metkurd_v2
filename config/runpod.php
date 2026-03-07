<?php

return [
    'base_url' => env('RUNPOD_BASE_URL', 'https://api.runpod.ai'),
    'api_key'  => env('RUNPOD_API_KEY'),

    // fallback endpoints (if tool.meta not set)
    'endpoints' => [
        'xtts' => env('RUNPOD_ENDPOINT_ID_XTTS'),
        'wasr' => env('RUNPOD_ENDPOINT_ID_WASR'),
        'stem' => env('RUNPOD_ENDPOINT_ID_STEM'),
        'kocr' => env('RUNPOD_ENDPOINT_ID_KOCR'),
    ],

    'timeout' => (int) env('RUNPOD_TIMEOUT', 60),
];
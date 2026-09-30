<?php

return [
    'max_actions' => (int) env('PROVIDER_REVIEW_MAX_ACTIONS', 100),
    'max_gets' => (int) env('PROVIDER_REVIEW_MAX_GETS', 25),
    'get_interval_ms' => (int) env('PROVIDER_REVIEW_GET_INTERVAL_MS', 1000),
    'release_revision' => env('PROVIDER_REVIEW_RELEASE_REVISION'),
];

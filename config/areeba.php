<?php

$defaultMode = strtolower(trim((string) env('APP_ENV', 'production'))) === 'production' ? 'live' : 'test';
$mode = strtolower(trim((string) env('AREEBA_MODE', $defaultMode)));
$mode = $mode === 'live' ? 'live' : 'test';
$modeSuffix = strtoupper($mode);

return [
    'mode' => $mode,
    'base_url' => env('AREEBA_BASE_URL') ?: env("AREEBA_BASE_URL_{$modeSuffix}", 'https://gateway.areebapayment.com/api/v3'),
    'username' => env('AREEBA_USERNAME') ?: env("AREEBA_USERNAME_{$modeSuffix}"),
    'password' => env('AREEBA_PASSWORD') ?: env("AREEBA_PASSWORD_{$modeSuffix}"),
    'api_key' => env('AREEBA_API_KEY') ?: env("AREEBA_API_KEY_{$modeSuffix}"),
    'webhook_secret' => env('AREEBA_WEBHOOK_SECRET'),
    'webhook_secret_header' => env('AREEBA_WEBHOOK_SECRET_HEADER', 'x-webhook-secret'),
    'with_register_enabled' => (bool) env('AREEBA_WITH_REGISTER_ENABLED', false),
    'schedule_enabled' => (bool) env('AREEBA_SCHEDULE_ENABLED', false),
    'currency' => 'IQD',
];

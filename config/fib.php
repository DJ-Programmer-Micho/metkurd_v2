<?php

$environment = strtolower(trim((string) env('FIB_ENV', 'staging')));
$environment = $environment === 'production' ? 'production' : 'staging';
$envSuffix = strtoupper($environment);

return [
    'environment' => $environment,
    'base_url' => env('FIB_BASE_URL') ?: env("FIB_BASE_URL_{$envSuffix}", 'https://fib.stage.fib.iq'),
    'realm' => env('FIB_REALM', 'fib-online-shop'),
    'client_id' => env('FIB_CLIENT_ID') ?: env("FIB_CLIENT_ID_{$envSuffix}"),
    'client_secret' => env('FIB_CLIENT_SECRET') ?: env("FIB_CLIENT_SECRET_{$envSuffix}"),
    'callback_secret' => env('FIB_CALLBACK_SECRET'),
    'callback_secret_header' => env('FIB_CALLBACK_SECRET_HEADER', 'x-callback-secret'),
    'currency' => 'IQD',
    'http' => [
        'timeout' => (int) env('FIB_HTTP_TIMEOUT', 15),
        'retries' => (int) env('FIB_HTTP_RETRIES', 2),
        'retry_sleep_ms' => (int) env('FIB_HTTP_RETRY_SLEEP_MS', 200),
    ],
    'token_ttl_seconds' => (int) env('FIB_TOKEN_TTL_SECONDS', 60),
    'status_sync' => [
        'delay_seconds' => (int) env('FIB_STATUS_SYNC_DELAY_SECONDS', 15),
        'max_attempts' => (int) env('FIB_STATUS_SYNC_MAX_ATTEMPTS', 5),
    ],
    'paths' => [
        'token' => env('FIB_TOKEN_PATH', '/auth/realms/{realm}/protocol/openid-connect/token'),
        'payments' => env('FIB_PAYMENTS_PATH', '/protected/v1/payments'),
        'payment_status' => env('FIB_PAYMENT_STATUS_PATH', '/protected/v1/payments/{paymentId}/status'),
        'payment_cancel' => env('FIB_PAYMENT_CANCEL_PATH', '/protected/v1/payments/{paymentId}/cancel'),
        'payment_refund' => env('FIB_PAYMENT_REFUND_PATH', '/protected/v1/payments/{paymentId}/refund'),
    ],
];

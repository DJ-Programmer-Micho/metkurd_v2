<?php

$environment = strtolower(trim((string) env('FIB_ENV', 'staging')));
$environment = $environment === 'production' ? 'production' : 'staging';
$envSuffix = strtoupper($environment);

$envBool = static function (string $key, bool $default = false): bool {
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (bool) $value;
    }

    $parsed = filter_var((string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    return $parsed ?? $default;
};

$pick = static function (array $candidates, ?string $default = null, ?string $defaultSource = null): array {
    foreach ($candidates as $source => $value) {
        if (! is_scalar($value)) {
            continue;
        }

        $value = trim((string) $value);

        if ($value !== '') {
            return [
                'value' => $value,
                'source' => $source,
            ];
        }
    }

    return [
        'value' => $default,
        'source' => $default !== null ? ($defaultSource ?? 'default') : null,
    ];
};

$paymentBaseUrl = $pick([
    'FIB_PAYMENT_BASE_URL' => env('FIB_PAYMENT_BASE_URL'),
    "FIB_PAYMENT_BASE_URL_{$envSuffix}" => env("FIB_PAYMENT_BASE_URL_{$envSuffix}"),
    'legacy:FIB_BASE_URL' => env('FIB_BASE_URL'),
    "legacy:FIB_BASE_URL_{$envSuffix}" => env("FIB_BASE_URL_{$envSuffix}"),
], $environment === 'production' ? 'https://fib.prod.fib.iq' : 'https://fib.stage.fib.iq', 'default:official_docs');

$subscriptionBaseUrl = $pick([
    'FIB_SUBSCRIPTION_BASE_URL' => env('FIB_SUBSCRIPTION_BASE_URL'),
    "FIB_SUBSCRIPTION_BASE_URL_{$envSuffix}" => env("FIB_SUBSCRIPTION_BASE_URL_{$envSuffix}"),
    'legacy:FIB_BASE_URL' => env('FIB_BASE_URL'),
    "legacy:FIB_BASE_URL_{$envSuffix}" => env("FIB_BASE_URL_{$envSuffix}"),
], $environment === 'production' ? 'https://fib.prod.fib.iq' : 'https://fib.stage.fib.iq', 'default:official_docs');

$paymentClientId = $pick([
    'FIB_PAYMENT_CLIENT_ID' => env('FIB_PAYMENT_CLIENT_ID'),
    'legacy:FIB_CLIENT_ID' => env('FIB_CLIENT_ID'),
    "legacy:FIB_CLIENT_ID_{$envSuffix}" => env("FIB_CLIENT_ID_{$envSuffix}"),
]);

$paymentClientSecret = $pick([
    'FIB_PAYMENT_CLIENT_SECRET' => env('FIB_PAYMENT_CLIENT_SECRET'),
    'legacy:FIB_CLIENT_SECRET' => env('FIB_CLIENT_SECRET'),
    "legacy:FIB_CLIENT_SECRET_{$envSuffix}" => env("FIB_CLIENT_SECRET_{$envSuffix}"),
]);

$subscriptionClientId = $pick([
    'FIB_SUBSCRIPTION_CLIENT_ID' => env('FIB_SUBSCRIPTION_CLIENT_ID'),
    'legacy:FIB_CLIENT_ID' => env('FIB_CLIENT_ID'),
    "legacy:FIB_CLIENT_ID_{$envSuffix}" => env("FIB_CLIENT_ID_{$envSuffix}"),
]);

$subscriptionClientSecret = $pick([
    'FIB_SUBSCRIPTION_CLIENT_SECRET' => env('FIB_SUBSCRIPTION_CLIENT_SECRET'),
    'legacy:FIB_CLIENT_SECRET' => env('FIB_CLIENT_SECRET'),
    "legacy:FIB_CLIENT_SECRET_{$envSuffix}" => env("FIB_CLIENT_SECRET_{$envSuffix}"),
]);

return [
    'enabled' => $envBool('FIB_ENABLED', false),
    'environment' => $environment,
    'realm' => env('FIB_REALM', 'fib-online-shop'),
    'currency' => 'IQD',
    'callback_base_url' => rtrim((string) env('FIB_CALLBACK_BASE_URL', env('APP_URL')), '/'),
    'callback_secret' => env('FIB_CALLBACK_SECRET'),
    'callback_secret_header' => env('FIB_CALLBACK_SECRET_HEADER', 'x-callback-secret'),
    'token_ttl_seconds' => (int) env('FIB_TOKEN_TTL_SECONDS', 60),
    'status_sync' => [
        'delay_seconds' => (int) env('FIB_STATUS_SYNC_DELAY_SECONDS', 15),
        'max_attempts' => (int) env('FIB_STATUS_SYNC_MAX_ATTEMPTS', 5),
    ],
    'reconciliation' => [
        'enabled' => $envBool('FIB_RECONCILIATION_ENABLED', true),
        'chunk_size' => (int) env('FIB_RECONCILIATION_CHUNK_SIZE', 100),
        'stale_minutes' => (int) env('FIB_RECONCILIATION_STALE_MINUTES', 5),
        'local_expiry_grace_minutes' => (int) env('FIB_RECONCILIATION_LOCAL_EXPIRY_GRACE_MINUTES', 0),
    ],
    'payment' => [
        'category' => env('FIB_PAYMENT_CATEGORY', 'ECOMMERCE'),
        'expires_in' => env('FIB_PAYMENT_EXPIRES_IN', 'PT1H'),
        'refundable_for' => env('FIB_PAYMENT_REFUNDABLE_FOR', 'PT48H'),
    ],
    'subscription' => [
        'expires_in' => env('FIB_SUBSCRIPTION_EXPIRES_IN', 'PT1H'),
        'trial_period' => env('FIB_SUBSCRIPTION_TRIAL_PERIOD'),
        'hourly_testing_enabled' => $envBool('FIB_SUBSCRIPTION_HOURLY_TESTING_ENABLED', false),
        'intervals' => [
            'monthly' => env('FIB_SUBSCRIPTION_INTERVAL_MONTHLY', 'P1M'),
            'yearly' => env('FIB_SUBSCRIPTION_INTERVAL_YEARLY', 'P1Y'),
            'hourly' => env('FIB_SUBSCRIPTION_INTERVAL_HOURLY', 'PT1H'),
        ],
    ],
    'http' => [
        'timeout' => (int) env('FIB_HTTP_TIMEOUT', 15),
        'retries' => (int) env('FIB_HTTP_RETRIES', 2),
        'retry_sleep_ms' => (int) env('FIB_HTTP_RETRY_SLEEP_MS', 200),
    ],
    'diagnostics' => [
        'enabled' => $envBool('FIB_DIAGNOSTICS_ENABLED', false),
    ],
    'paths' => [
        'token' => env('FIB_TOKEN_PATH', '/auth/realms/{realm}/protocol/openid-connect/token'),
        'payments' => env('FIB_PAYMENTS_PATH', '/protected/v1/payments'),
        'payment_status' => env('FIB_PAYMENT_STATUS_PATH', '/protected/v1/payments/{paymentId}/status'),
        'payment_cancel' => env('FIB_PAYMENT_CANCEL_PATH', '/protected/v1/payments/{paymentId}/cancel'),
        'payment_refund' => env('FIB_PAYMENT_REFUND_PATH', '/protected/v1/payments/{paymentId}/refund'),
        'subscriptions' => env('FIB_SUBSCRIPTIONS_PATH', '/protected/v1/subscriptions'),
        'subscription_status' => env('FIB_SUBSCRIPTION_STATUS_PATH', '/protected/v1/subscriptions/{subscriptionId}'),
        'subscription_cancel' => env('FIB_SUBSCRIPTION_CANCEL_PATH', '/protected/v1/subscriptions/{subscriptionId}/cancel'),
    ],
    'profiles' => [
        'payment' => [
            'base_url' => $paymentBaseUrl['value'],
            'base_url_source' => $paymentBaseUrl['source'],
            'client_id' => $paymentClientId['value'],
            'client_id_source' => $paymentClientId['source'],
            'client_secret' => $paymentClientSecret['value'],
            'client_secret_source' => $paymentClientSecret['source'],
        ],
        'subscription' => [
            'base_url' => $subscriptionBaseUrl['value'],
            'base_url_source' => $subscriptionBaseUrl['source'],
            'client_id' => $subscriptionClientId['value'],
            'client_id_source' => $subscriptionClientId['source'],
            'client_secret' => $subscriptionClientSecret['value'],
            'client_secret_source' => $subscriptionClientSecret['source'],
        ],
    ],
];

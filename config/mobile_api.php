<?php

return [
    'token_expiration_days' => (int) env('MOBILE_API_TOKEN_EXPIRATION_DAYS', 90),
    'onboarding_token_expiration_minutes' => (int) env('MOBILE_API_ONBOARDING_TOKEN_EXPIRATION_MINUTES', 120),
    'download_url_ttl_minutes' => (int) env('MOBILE_API_DOWNLOAD_URL_TTL_MINUTES', 30),
    'phone_otp' => [
        'ttl_seconds' => (int) env('MOBILE_PHONE_OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('MOBILE_PHONE_OTP_MAX_ATTEMPTS', 5),
        'lock_seconds' => (int) env('MOBILE_PHONE_OTP_LOCK_SECONDS', 600),
        'cooldown_seconds' => (int) env('MOBILE_PHONE_OTP_COOLDOWN_SECONDS', 60),
    ],
];

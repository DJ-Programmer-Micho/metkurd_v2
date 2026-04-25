<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'youtube' => [
        'python_target' => env('YOUTUBE_PYTHON_TARGET', 'default'),
        'python_bin' => env('YOUTUBE_PYTHON_BIN', 'python'),
        'temp_dir' => env('YOUTUBE_TEMP_DIR', sys_get_temp_dir()),
        'output_disk' => env('YOUTUBE_OUTPUT_DISK', env('APP_ENV') === 'local' ? 'local' : 's3'),
        'output_prefix' => env('YOUTUBE_OUTPUT_PREFIX', 'tmp/youtube-downloads'),
        'output_ttl_minutes' => (int) env('YOUTUBE_OUTPUT_TTL_MINUTES', 60),
        'download_url_ttl_minutes' => (int) env('YOUTUBE_DOWNLOAD_URL_TTL_MINUTES', 15),
        'cookies_browser' => env('YOUTUBE_COOKIES_BROWSER', ''),
        'cookies_browsers' => array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) env('YOUTUBE_COOKIES_BROWSERS', ''))
        ))),
        'cookies_browser_profile' => env('YOUTUBE_COOKIES_BROWSER_PROFILE', ''),
        'cookies_file' => env('YOUTUBE_COOKIES_FILE', ''),
        'python_bins' => [
            'windows_local' => env('YOUTUBE_PYTHON_BIN_WINDOWS_LOCAL'),
            'linux_aws_ec2' => env('YOUTUBE_PYTHON_BIN_LINUX_AWS_EC2'),
        ],
    ],
    'standingtech' => [
        'base' => env('STANDINGTECH_BASE_URL'),
        'token' => env('STANDINGTECH_TOKEN'),
        'sender' => env('STANDINGTECH_SENDER_ID'),
    ],
    'runpod' => [
        'api_key' => env('RUNPOD_API_KEY'),
        'base_url' => env('RUNPOD_BASE_URL', 'https://api.runpod.ai'),
        'timeout' => (int) env('RUNPOD_TIMEOUT', 60),

        // ✅ multiple endpoints
        'endpoints' => [
            'xtts' => env('RUNPOD_ENDPOINT_ID_XTTS'),
            'ftts' => env('RUNPOD_ENDPOINT_ID_FTTS'),
            'wasr' => env('RUNPOD_ENDPOINT_ID_WASR'),
            'qasr' => env('RUNPOD_ENDPOINT_ID_QASR'),
            'tran' => env('RUNPOD_ENDPOINT_ID_TRAN'),
            'stem' => env('RUNPOD_ENDPOINT_ID_STEM'),
            'kocr' => env('RUNPOD_ENDPOINT_ID_KOCR'),
        ],
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],
    'telegram-bot-api' => [
        'token' => env('TELEGRAM_BOT_TOKEN', '7860562413:AAF7NeKkAZBS433KxwfZ1DtekirBllvPLxY'),
        'groups' => [
            'legacy' => env('TELEGRAM_GROUP', ''),
            'contact' => env('TELEGRAM_GROUP_CON', env('TELEGRAM_CHAT_ID', env('TELEGRAM_GROUP', ''))),
            'checkout' => env('TELEGRAM_GROUP_CHK', env('TELEGRAM_GROUP', env('TELEGRAM_GROUP_PAY', ''))),
            'payment' => env('TELEGRAM_GROUP_PAY', env('TELEGRAM_GROUP', '')),
        ],
    ],

];

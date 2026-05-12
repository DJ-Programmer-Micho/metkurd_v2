<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Landing Media Disk
    |--------------------------------------------------------------------------
    |
    | Landing/admin-managed dynamic media (tool images, meta images, favicon,
    | etc.) must live on shared storage in load-balanced deployments.
    |
    */
    'media_disk' => env('LANDING_MEDIA_DISK', env('AWS_BUCKET') ? 's3' : 'public'),

    /*
    |--------------------------------------------------------------------------
    | Landing Media URL Strategy
    |--------------------------------------------------------------------------
    |
    | "proxy": serve media through an app route (works with private buckets).
    | "direct": use the storage disk URL directly (works for public buckets).
    |
    */
    'media_url_strategy' => env('LANDING_MEDIA_URL_STRATEGY', 'proxy'),

    /*
    |--------------------------------------------------------------------------
    | Public Media Prefixes
    |--------------------------------------------------------------------------
    |
    | Only these key prefixes are allowed for publicly rendered landing assets.
    | Legacy prefixes are kept for backwards compatibility with existing rows.
    |
    */
    'public_media_prefixes' => [
        'web-setting/',
        'site-meta/',
        'landing/tools/',
        'landing/demos/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxy Cache Lifetime
    |--------------------------------------------------------------------------
    |
    | Cache lifetime for media served through the app-controlled proxy route.
    |
    */
    'media_proxy_max_age' => (int) env('LANDING_MEDIA_PROXY_MAX_AGE', 3600),
];

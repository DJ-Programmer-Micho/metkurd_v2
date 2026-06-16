<?php

return [
    'key_prefix' => env('CUSTOMER_API_KEY_PREFIX', 'mk_live_'),
    'max_keys' => max(1, (int) env('CUSTOMER_API_MAX_KEYS', 5)),
    'temporary_file_ttl_days' => max(1, (int) env('CUSTOMER_API_TEMP_FILE_TTL_DAYS', 7)),
    'download_url_ttl_minutes' => max(1, (int) env('CUSTOMER_API_DOWNLOAD_URL_TTL_MINUTES', 5)),
];

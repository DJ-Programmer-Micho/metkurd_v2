<?php

return [
    'token_expiration_days' => (int) env('MOBILE_API_TOKEN_EXPIRATION_DAYS', 90),
    'download_url_ttl_minutes' => (int) env('MOBILE_API_DOWNLOAD_URL_TTL_MINUTES', 30),
];

<?php

return [
    'enabled' => (bool) env('FEATURE_MCP_V2', false),
    'public_url' => env('MCP_PUBLIC_URL', 'https://metkurd.ai/mcp'),
    'issuer' => env('MCP_OAUTH_ISSUER', 'https://metkurd.ai'),
    'token_minutes' => (int) env('MCP_TOKEN_TTL', 10),
    'refresh_days' => (int) env('MCP_REFRESH_TOKEN_TTL', 30),
    'session_store' => env('MCP_SESSION_STORE', 'redis'),
    'session_seconds' => 3600,
    'max_body_bytes' => 262144,
    'inline_file_bytes' => (int) env('MCP_INLINE_FILE_BYTES', 1048576),
    'inline_total_bytes' => (int) env('MCP_INLINE_TOTAL_BYTES', 1048576),
    'response_bytes' => (int) env('MCP_RESPONSE_BYTES', 2097152),
    'upload_minutes' => 15,
    'origins' => array_values(array_filter(explode(',', env('MCP_ALLOWED_ORIGINS', 'https://metkurd.ai')))),
];

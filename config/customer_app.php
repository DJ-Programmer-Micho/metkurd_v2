<?php

return [
    // Legacy workspace availability; independent of App V2, REST API and MCP.
    'v1_enabled' => (bool) env('FEATURE_APP_V1', true),
];

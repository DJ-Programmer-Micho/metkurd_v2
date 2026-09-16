<?php

return [
    // Assertions about Laravel's existing connection, never connection overrides.
    'enabled' => env('BILLING_CUTOVER_ENABLED', false),
    'target' => env('BILLING_CUTOVER_TARGET'),
    'expected_host' => env('BILLING_CUTOVER_EXPECTED_HOST'),
    'expected_database' => env('BILLING_CUTOVER_EXPECTED_DATABASE'),
    'expected_port' => env('BILLING_CUTOVER_EXPECTED_PORT'),
    'admin_id' => env('BILLING_CUTOVER_ADMIN_ID'),
    'backup_reference' => env('BILLING_CUTOVER_BACKUP_REFERENCE'),
    'restore_reference' => env('BILLING_CUTOVER_RESTORE_REFERENCE'),
];

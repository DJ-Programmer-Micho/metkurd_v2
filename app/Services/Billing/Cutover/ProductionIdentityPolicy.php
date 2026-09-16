<?php

namespace App\Services\Billing\Cutover;

use App\Services\Billing\PaymentHistoryResetRefused;

class ProductionIdentityPolicy
{
    public function configuration(array $context): void
    {
        if ($context['environment'] !== 'production' || ! is_string($context['host'])
            || ! preg_match('/^[a-z0-9][a-z0-9.-]+\.rds\.amazonaws\.com(?:\.cn)?$/D', $context['host'])) {
            throw new PaymentHistoryResetRefused('Production requires APP_ENV=production and an explicitly asserted RDS endpoint.');
        }
    }

    public function server(array $server): void
    {
        if ($server['engine_family'] !== 'mysql' || empty($server['server_uuid']) || ($server['replica_channels'] ?? null) !== 0) {
            throw new PaymentHistoryResetRefused('Production requires native MySQL server identity with no replica channels; MariaDB and replicas are not accepted.');
        }
        // RDS's SQL hostname is not the application machine's hostname.
    }
}

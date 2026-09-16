<?php

namespace App\Services\Billing\Cutover;

use App\Services\Billing\PaymentHistoryResetRefused;

class LocalRehearsalIdentityPolicy
{
    public function configuration(array $context): void
    {
        if ($context['environment'] !== 'local' || ! in_array($context['host'], ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new PaymentHistoryResetRefused('Local rehearsal requires APP_ENV=local and a loopback host.');
        }
    }

    public function server(array $server): void
    {
        if (strcasecmp((string) $server['hostname'], gethostname()) !== 0) {
            throw new PaymentHistoryResetRefused('Local SQL hostname must match this machine; remote tunnels are refused.');
        }
    }
}

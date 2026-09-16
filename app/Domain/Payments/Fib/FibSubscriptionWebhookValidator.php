<?php

namespace App\Domain\Payments\Fib;

use Illuminate\Http\Request;

class FibSubscriptionWebhookValidator
{
    /**
     * @return array{valid:bool,subscription_id:?string,issues:array<int,string>}
     */
    public function validate(Request $request): array
    {
        $issues = [];
        $subscriptionId = \App\Domain\Payments\Support\FibCallbackNotification::identifier($request, 'subscriptionId');

        if ($subscriptionId === null) {
            $issues[] = 'Missing subscription identifier.';
        }

        // Optional operator delivery filter; never sufficient payment evidence.
        $configuredSecret = trim((string) config('fib.callback_secret'));
        $configuredHeader = trim((string) config('fib.callback_secret_header', 'x-callback-secret'));

        if ($configuredSecret !== '') {
            $actual = trim((string) $request->header($configuredHeader, ''));

            if ($actual === '' || ! hash_equals($configuredSecret, $actual)) {
                $issues[] = 'Callback secret validation failed.';
            }
        }

        return [
            'valid' => $issues === [],
            'subscription_id' => $subscriptionId,
            'issues' => $issues,
        ];
    }
}

<?php

namespace App\Domain\Payments\Fib;

use Illuminate\Http\Request;

class FibOneTimeWebhookValidator
{
    /**
     * @return array{valid:bool,payment_id:?string,issues:array<int,string>}
     */
    public function validate(Request $request): array
    {
        $issues = [];
        $paymentId = \App\Domain\Payments\Support\FibCallbackNotification::identifier($request, 'paymentId');

        if ($paymentId === null) {
            $issues[] = 'Missing payment identifier.';
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
            'payment_id' => $paymentId,
            'issues' => $issues,
        ];
    }
}

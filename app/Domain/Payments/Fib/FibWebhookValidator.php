<?php

namespace App\Domain\Payments\Fib;

use Illuminate\Http\Request;

class FibWebhookValidator
{
    /**
     * @return array{valid:bool,payment_id:?string,issues:array<int,string>}
     */
    public function validate(Request $request): array
    {
        $issues = [];
        $paymentId = trim((string) ($request->input('id') ?: $request->input('paymentId') ?: ''));

        if ($paymentId === '') {
            $issues[] = 'Missing payment identifier.';
        }

        $configuredSecret = trim((string) config('services.fib.callback_secret'));
        $configuredHeader = trim((string) config('services.fib.callback_secret_header', 'x-callback-secret'));

        if ($configuredSecret !== '') {
            $actual = trim((string) $request->header($configuredHeader, ''));

            if ($actual === '' || ! hash_equals($configuredSecret, $actual)) {
                $issues[] = 'Callback secret validation failed.';
            }
        }

        return [
            'valid' => $issues === [],
            'payment_id' => $paymentId !== '' ? $paymentId : null,
            'issues' => $issues,
        ];
    }
}

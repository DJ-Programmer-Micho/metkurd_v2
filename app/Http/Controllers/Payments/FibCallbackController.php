<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Fib\FibOneTimeWebhookValidator;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Http\Controllers\Controller;
use App\Jobs\Payments\ProcessFibPaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FibCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        FibOneTimeWebhookValidator $validator,
        PaymentEventRecorder $events,
    ): JsonResponse {
        $payload = \App\Domain\Payments\Support\FibCallbackNotification::payload($request);
        $validation = $validator->validate($request);

        Log::info('FIB one-time callback received.', [
            'provider_object_type' => 'payment',
            'vm_hostname' => gethostname() ?: php_uname('n'),
            'request_url' => $request->url(),
            'request_host' => $request->getHost(),
            'request_ip' => $request->ip(),
            'x_forwarded_for' => $request->header('x-forwarded-for'),
            'cf_connecting_ip' => $request->header('cf-connecting-ip'),
            'payment_id' => $validation['payment_id'],
            'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
        ]);

        if (! $validation['valid']) {
            Log::warning('FIB one-time callback rejected by validator.', [
                'provider_object_type' => 'payment',
                'vm_hostname' => gethostname() ?: php_uname('n'),
                'payment_id' => $validation['payment_id'],
                'issues' => $validation['issues'],
                'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
            ]);

            $events->record(null, [
                'event_type' => 'callback_rejected',
                'source' => 'fib_callback',
                'event_key' => sha1(json_encode($payload)),
                'fib_payment_id' => $validation['payment_id'],
                'response_code' => 406,
                'payload' => $payload,
                'meta' => [
                    'issues' => $validation['issues'],
                ],
            ]);

            return response()->json([
                'ok' => false,
                'issues' => $validation['issues'],
            ], 406);
        }

        $payment = Payment::query()
            ->where('provider', 'fib')
            ->where('provider_object_type', PaymentProviderObjectType::PAYMENT)
            ->where('fib_payment_id', (string) $validation['payment_id'])
            ->first();

        if ($payment instanceof Payment) {
            \App\Domain\Payments\Support\FibCallbackNotification::received($payment, $payload);
        }

        if (! $payment instanceof Payment) {
            Log::warning('FIB one-time callback did not match a local payment.', [
                'provider_object_type' => 'payment',
                'vm_hostname' => gethostname() ?: php_uname('n'),
                'payment_id' => $validation['payment_id'],
                'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
            ]);

            $events->record(null, [
                'event_type' => 'callback_orphaned',
                'source' => 'fib_callback',
                'event_key' => 'callback-orphaned:'.(string) $validation['payment_id'].':'.sha1(json_encode($payload)),
                'fib_payment_id' => (string) $validation['payment_id'],
                'response_code' => 202,
                'payload' => $payload,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'accepted',
            ], 202);
        }

        if (app()->environment('testing')) {
            ProcessFibPaymentStatus::dispatchSync(
                PaymentProviderObjectType::PAYMENT->value,
                (string) $validation['payment_id'],
                $payload,
                'fib_callback',
            );
        } else {
            ProcessFibPaymentStatus::dispatch(
                PaymentProviderObjectType::PAYMENT->value,
                (string) $validation['payment_id'],
                $payload,
                'fib_callback',
            );
        }

        return response()->json([
            'ok' => true,
            'status' => 'accepted',
        ], 202);
    }
}

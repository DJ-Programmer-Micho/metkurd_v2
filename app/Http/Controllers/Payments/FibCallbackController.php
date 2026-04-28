<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Fib\FibOneTimeWebhookValidator;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FibCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        FibOneTimeWebhookValidator $validator,
        SyncFibCheckoutStatus $sync,
        PaymentEventRecorder $events,
    ): JsonResponse {
        $payload = $request->all();
        $validation = $validator->validate($request);

        Log::info('FIB one-time callback received.', [
            'provider_object_type' => 'payment',
            'vm_hostname' => gethostname() ?: php_uname('n'),
            'request_url' => $request->fullUrl(),
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

        try {
            $payment = $sync->handleByFibPaymentId((string) $validation['payment_id'], 'callback', $payload);

            if ($payment === null) {
                Log::warning('FIB one-time callback did not match a local payment.', [
                    'provider_object_type' => 'payment',
                    'vm_hostname' => gethostname() ?: php_uname('n'),
                    'payment_id' => $validation['payment_id'],
                    'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
                ]);

                $events->record(null, [
                    'event_type' => 'callback_orphaned',
                    'source' => 'fib_callback',
                    'event_key' => 'callback:' . (string) $validation['payment_id'] . ':' . sha1(json_encode($payload)),
                    'fib_payment_id' => (string) $validation['payment_id'],
                    'response_code' => 202,
                    'payload' => $payload,
                ]);

                return response()->json([
                    'ok' => true,
                    'status' => 'accepted',
                ], 202);
            }

            Log::info('FIB one-time callback processed.', [
                'provider_object_type' => 'payment',
                'vm_hostname' => gethostname() ?: php_uname('n'),
                'payment_uuid' => (string) $payment->uuid,
                'provider_reference' => $payment->providerReference(),
                'local_status' => $payment->status->value,
                'provider_status' => $payment->providerStatusLabel(),
                'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
            ]);

            $events->record($payment, [
                'event_type' => 'callback_processed',
                'source' => 'fib_callback',
                'event_key' => 'callback:' . (string) $validation['payment_id'] . ':' . sha1(json_encode($payload)),
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'response_code' => 202,
                'payload' => $payload,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'accepted',
            ], 202);
        } catch (\Throwable $exception) {
            Log::error('FIB one-time callback processing failed.', [
                'provider_object_type' => 'payment',
                'vm_hostname' => gethostname() ?: php_uname('n'),
                'payment_id' => $validation['payment_id'],
                'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
                'message' => $exception->getMessage(),
            ]);

            $events->record(null, [
                'event_type' => 'callback_failed',
                'source' => 'fib_callback',
                'event_key' => 'callback:' . (string) $validation['payment_id'] . ':' . sha1(json_encode($payload)),
                'fib_payment_id' => (string) $validation['payment_id'],
                'response_code' => 500,
                'payload' => $payload,
                'meta' => [
                    'message' => $exception->getMessage(),
                ],
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'callback-processing-failed',
            ], 500);
        }
    }
}

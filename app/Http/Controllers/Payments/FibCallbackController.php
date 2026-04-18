<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Fib\FibWebhookValidator;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FibCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        FibWebhookValidator $validator,
        ConfirmFibPayment $confirm,
        PaymentEventRecorder $events,
    ): JsonResponse {
        $payload = $request->all();
        $validation = $validator->validate($request);

        if (! $validation['valid']) {
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
            $payment = $confirm->handleByFibPaymentId((string) $validation['payment_id'], 'callback', $payload);

            if ($payment === null) {
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

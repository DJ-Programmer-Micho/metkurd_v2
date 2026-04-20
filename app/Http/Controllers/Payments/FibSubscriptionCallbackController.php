<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Fib\FibSubscriptionWebhookValidator;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FibSubscriptionCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        FibSubscriptionWebhookValidator $validator,
        SyncFibCheckoutStatus $sync,
        PaymentEventRecorder $events,
    ): JsonResponse {
        $payload = $request->all();
        $validation = $validator->validate($request);

        if (! $validation['valid']) {
            $events->record(null, [
                'event_type' => 'callback_rejected',
                'source' => 'fib_subscription_callback',
                'event_key' => sha1(json_encode($payload)),
                'fib_subscription_id' => $validation['subscription_id'],
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
            $payment = $sync->handleByFibSubscriptionId((string) $validation['subscription_id'], 'callback', $payload);

            if ($payment === null) {
                $events->record(null, [
                    'event_type' => 'callback_orphaned',
                    'source' => 'fib_subscription_callback',
                    'event_key' => 'subscription-callback:' . (string) $validation['subscription_id'] . ':' . sha1(json_encode($payload)),
                    'fib_subscription_id' => (string) $validation['subscription_id'],
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
                'source' => 'fib_subscription_callback',
                'event_key' => 'subscription-callback:' . (string) $validation['subscription_id'] . ':' . sha1(json_encode($payload)),
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
                'source' => 'fib_subscription_callback',
                'event_key' => 'subscription-callback:' . (string) $validation['subscription_id'] . ':' . sha1(json_encode($payload)),
                'fib_subscription_id' => (string) $validation['subscription_id'],
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

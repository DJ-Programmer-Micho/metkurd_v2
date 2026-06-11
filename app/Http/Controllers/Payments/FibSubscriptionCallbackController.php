<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Fib\FibSubscriptionWebhookValidator;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Http\Controllers\Controller;
use App\Jobs\Payments\ProcessFibPaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FibSubscriptionCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        FibSubscriptionWebhookValidator $validator,
        PaymentEventRecorder $events,
    ): JsonResponse {
        $payload = $request->all();
        $validation = $validator->validate($request);

        Log::info('FIB subscription callback received.', [
            'provider_object_type' => 'subscription',
            'vm_hostname' => gethostname() ?: php_uname('n'),
            'request_url' => $request->fullUrl(),
            'request_host' => $request->getHost(),
            'request_ip' => $request->ip(),
            'x_forwarded_for' => $request->header('x-forwarded-for'),
            'cf_connecting_ip' => $request->header('cf-connecting-ip'),
            'subscription_id' => $validation['subscription_id'],
            'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
        ]);

        if (! $validation['valid']) {
            Log::warning('FIB subscription callback rejected by validator.', [
                'provider_object_type' => 'subscription',
                'vm_hostname' => gethostname() ?: php_uname('n'),
                'subscription_id' => $validation['subscription_id'],
                'issues' => $validation['issues'],
                'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
            ]);

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

        $payment = Payment::query()
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->where('fib_subscription_id', (string) $validation['subscription_id'])
            ->first();

        $events->record($payment, [
            'event_type' => 'callback_received',
            'source' => 'fib_subscription_callback',
            'event_key' => 'subscription-callback-received:'.(string) $validation['subscription_id'].':'.sha1(json_encode($payload)),
            'fib_subscription_id' => (string) $validation['subscription_id'],
            'response_code' => 202,
            'payload' => $payload,
            'meta' => [
                'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
            ],
        ]);

        if (! $payment instanceof Payment) {
            Log::warning('FIB subscription callback did not match a local payment.', [
                'provider_object_type' => 'subscription',
                'vm_hostname' => gethostname() ?: php_uname('n'),
                'subscription_id' => $validation['subscription_id'],
                'payload_status' => is_scalar(data_get($payload, 'status')) ? (string) data_get($payload, 'status') : null,
            ]);

            $events->record(null, [
                'event_type' => 'callback_orphaned',
                'source' => 'fib_subscription_callback',
                'event_key' => 'subscription-callback-orphaned:'.(string) $validation['subscription_id'].':'.sha1(json_encode($payload)),
                'fib_subscription_id' => (string) $validation['subscription_id'],
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
                PaymentProviderObjectType::SUBSCRIPTION->value,
                (string) $validation['subscription_id'],
                $payload,
                'fib_subscription_callback',
            );
        } else {
            ProcessFibPaymentStatus::dispatch(
                PaymentProviderObjectType::SUBSCRIPTION->value,
                (string) $validation['subscription_id'],
                $payload,
                'fib_subscription_callback',
            );
        }

        return response()->json([
            'ok' => true,
            'status' => 'accepted',
        ], 202);
    }
}

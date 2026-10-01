<?php

namespace App\Services\Payments;

use App\Enums\PaymentTransactionType;
use App\Enums\PaymentWebhookProcessingStatus;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookService
{
    public function __construct(
        protected PaymentMethodCatalog $paymentMethods,
        protected PaymentProviderManager $providers,
        protected PaymentIntentReconciliationService $reconciler,
    ) {}

    public function handle(string $provider, Request $request): PaymentWebhookEvent
    {
        $provider = strtolower(trim($provider));
        // Native V2 FIB callbacks have their own current-Payment-only endpoints.
        abort_if($provider === 'fib' && app(\App\Services\Billing\BillingReportingBoundary::class)->fullReset(), 410);
        // Reject before recording or resolving any financial intent. A configured
        // header is a local delivery agreement, not an invented provider signature.
        abort_unless($this->providers->isEnabled($provider), 403);
        abort_if(strlen($request->getContent()) > 16384, 413);
        $payload = $request->all();
        $headers = []; // Never retain authentication headers or cookies.

        $paymentMethod = $this->paymentMethods->firstByDriver($provider, true);
        $providerAdapter = $this->providers->driver($provider);
        abort_unless($providerAdapter->validateWebhookSignature($request, $paymentMethod) === true, 403);
        foreach (['merchantTransactionId', 'merchant_transaction_id', 'merchantReference', 'paymentId', 'payment_id', 'id',
            'uuid', 'transactionId', 'transaction_id', 'purchaseId', 'purchase_id', 'status', 'transactionStatus', 'transaction_status',
            'type', 'eventType', 'transactionType'] as $key) {
            abort_if(isset($payload[$key]) && (! is_string($payload[$key]) || strlen($payload[$key]) > 80), 422);
        }
        abort_if(array_key_exists('amount', $payload) && (! is_numeric($payload['amount']) || strlen((string) $payload['amount']) > 32), 422);
        abort_if(array_key_exists('currency', $payload) && (! is_string($payload['currency']) || ! preg_match('/^[A-Za-z]{3}$/D', $payload['currency'])), 422);
        $normalized = $providerAdapter->normalizeWebhookPayload($payload);
        // Persist only operational evidence required by this compatibility path.
        $payload = array_intersect_key($payload, array_flip(['merchantTransactionId', 'merchant_transaction_id', 'merchantReference',
            'paymentId', 'payment_id', 'id', 'uuid', 'transactionId', 'transaction_id', 'purchaseId', 'purchase_id',
            'status', 'transactionStatus', 'transaction_status', 'amount', 'currency']));
        $normalized['raw'] = $payload;
        $signatureValid = true; // Already verified against the original request above.
        $eventKey = $this->eventKey($provider, $normalized, $payload);

        return DB::transaction(function () use ($provider, $headers, $payload, $normalized, $signatureValid, $eventKey) {
            $existing = PaymentWebhookEvent::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->processing_status === PaymentWebhookProcessingStatus::PROCESSED->value) {
                return $existing;
            }

            $intent = $this->resolveIntent($provider, $normalized);
            $boundary = app(\App\Services\Billing\BillingReportingBoundary::class)->fullReset();
            abort_if($boundary && (! $intent || ! $intent->created_at || $intent->created_at->lt($boundary['starts_at'])), 410);

            /** @var PaymentWebhookEvent $event */
            $event = $existing ?? PaymentWebhookEvent::create([
                'provider' => $provider,
                'event_key' => $eventKey,
                'payment_intent_id' => $intent?->id,
                'merchant_transaction_id' => $normalized['merchant_transaction_id'],
                'provider_payment_id' => $normalized['provider_payment_id'],
                'provider_transaction_id' => $normalized['provider_transaction_id'],
                'provider_purchase_id' => $normalized['provider_purchase_id'],
                'event_type' => $normalized['event_type'],
                'event_status' => $normalized['status'],
                'signature_valid' => $signatureValid,
                'processing_status' => PaymentWebhookProcessingStatus::RECEIVED->value,
                'received_at' => now(),
                'headers' => $headers,
                'payload' => $payload,
                'normalized_payload' => $normalized,
            ]);

            if ($existing !== null) {
                $event->forceFill([
                    'payment_intent_id' => $intent?->id,
                    'merchant_transaction_id' => $normalized['merchant_transaction_id'],
                    'provider_payment_id' => $normalized['provider_payment_id'],
                    'provider_transaction_id' => $normalized['provider_transaction_id'],
                    'provider_purchase_id' => $normalized['provider_purchase_id'],
                    'event_type' => $normalized['event_type'],
                    'event_status' => $normalized['status'],
                    'signature_valid' => $signatureValid,
                    'headers' => $headers,
                    'payload' => $payload,
                    'normalized_payload' => $normalized,
                ])->save();
            }

            if ($intent === null) {
                $event->forceFill([
                    'processing_status' => PaymentWebhookProcessingStatus::IGNORED->value,
                    'processed_at' => now(),
                    'response_code' => 202,
                    'error_message' => 'No matching payment intent was found for the webhook payload.',
                ])->save();

                return $event;
            }

            if (! $this->matchesIntent($intent, $normalized, $payload)) {
                $event->forceFill(['processing_status' => PaymentWebhookProcessingStatus::FAILED->value,
                    'processed_at' => now(), 'response_code' => 422,
                    'error_message' => 'Webhook reference or monetary evidence mismatch.'])->save();

                return $event;
            }

            $result = $this->reconciler->applyNormalizedUpdate($intent, array_merge($normalized, [
                'transaction_type' => PaymentTransactionType::WEBHOOK->value,
                'transaction_meta' => [
                    'event_id' => $event->id,
                    'event_key' => $event->event_key,
                ],
            ]));

            if (! $result['applied']) {
                $event->forceFill([
                    'processing_status' => PaymentWebhookProcessingStatus::IGNORED->value,
                    'processed_at' => now(),
                    'response_code' => 202,
                    'error_message' => $result['reason'],
                ])->save();

                return $event;
            }

            $event->forceFill([
                'processing_status' => PaymentWebhookProcessingStatus::PROCESSED->value,
                'processed_at' => now(),
                'response_code' => 200,
                'error_message' => null,
            ])->save();

            return $event->fresh();
        }, 3);
    }

    protected function eventKey(string $provider, array $normalized, array $payload): string
    {
        $parts = array_filter([
            $provider,
            $normalized['merchant_transaction_id'] ?? null,
            $normalized['provider_payment_id'] ?? null,
            $normalized['provider_transaction_id'] ?? null,
            $normalized['provider_purchase_id'] ?? null,
            $normalized['event_type'] ?? null,
            $normalized['status'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($parts === []) {
            $parts[] = sha1(json_encode($payload));
        }

        $key = implode(':', $parts);

        return strlen($key) <= 190 ? $key : $provider.':'.hash('sha256', $key);
    }

    protected function matchesIntent(PaymentIntent $intent, array $normalized, array $payload): bool
    {
        foreach (['merchant_transaction_id', 'provider_payment_id', 'provider_transaction_id', 'provider_purchase_id'] as $key) {
            $reported = $normalized[$key] ?? '';
            if ($reported !== '' && $intent->{$key} && $reported !== $intent->{$key}) {
                return false;
            }
        }
        if (array_key_exists('amount', $payload) && (! is_numeric($payload['amount'])
            || (float) $payload['amount'] !== (float) $intent->gross_amount_iqd)) {
            return false;
        }
        if (array_key_exists('currency', $payload) && (! is_string($payload['currency'])
            || strtoupper($payload['currency']) !== strtoupper($intent->base_currency_code ?: 'IQD'))) {
            return false;
        }

        return true;
    }

    protected function resolveIntent(string $provider, array $normalized): ?PaymentIntent
    {
        $query = PaymentIntent::query()->where('provider', $provider)->lockForUpdate();

        if (($normalized['merchant_transaction_id'] ?? '') !== '') {
            $intent = (clone $query)->where('merchant_transaction_id', $normalized['merchant_transaction_id'])->first();
            if ($intent !== null) {
                return $intent;
            }
        }

        foreach (['provider_transaction_id', 'provider_payment_id', 'provider_purchase_id'] as $column) {
            $value = (string) ($normalized[$column] ?? '');

            if ($value === '') {
                continue;
            }

            $matches = (clone $query)->where($column, $value)->limit(2)->get();
            if ($matches->isNotEmpty()) {
                return $matches->count() === 1 ? $matches->first() : null;
            }
        }

        return null;
    }
}

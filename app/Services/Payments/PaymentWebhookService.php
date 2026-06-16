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
        $payload = $request->all();
        $headers = collect($request->headers->all())
            ->map(fn (array $values) => count($values) === 1 ? $values[0] : $values)
            ->all();

        $paymentMethod = $this->paymentMethods->firstByDriver($provider, true);
        $providerAdapter = $this->providers->driver($provider);
        $normalized = $providerAdapter->normalizeWebhookPayload($payload);
        $signatureValid = $providerAdapter->validateWebhookSignature($request, $paymentMethod);
        $eventKey = $this->eventKey($provider, $normalized, $payload);

        return DB::transaction(function () use ($provider, $headers, $payload, $normalized, $signatureValid, $eventKey) {
            $existing = PaymentWebhookEvent::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->processing_status === PaymentWebhookProcessingStatus::PROCESSED->value) {
                return $existing;
            }

            $intent = $this->resolveIntent($normalized);

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

            if ($signatureValid === false) {
                $event->forceFill([
                    'processing_status' => PaymentWebhookProcessingStatus::FAILED->value,
                    'processed_at' => now(),
                    'error_message' => 'Webhook signature validation failed.',
                    'response_code' => 422,
                ])->save();

                return $event;
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

        return implode(':', $parts);
    }

    protected function resolveIntent(array $normalized): ?PaymentIntent
    {
        $query = PaymentIntent::query();

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

            $intent = (clone $query)->where($column, $value)->first();
            if ($intent !== null) {
                return $intent;
            }
        }

        return null;
    }
}

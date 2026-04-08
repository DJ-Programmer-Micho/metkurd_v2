<?php

namespace App\Services\Payments\Providers;

use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurposeType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

class FakePaymentProvider extends AbstractConfiguredPaymentProvider
{
    public function driver(): string
    {
        return 'fake';
    }

    public function isCheckoutReady(?PaymentMethod $method = null): bool
    {
        return true;
    }

    protected function hasCheckoutImplementation(?PaymentMethod $method = null): bool
    {
        return true;
    }

    public function recurringStrategy(PaymentMethod $method, PaymentPurposeType $purposeType): string
    {
        return $purposeType === PaymentPurposeType::CREDIT_PRODUCT ? 'none' : 'manual_renewal';
    }

    public function initializeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        Customer $customer,
        array $purpose,
        array $options = [],
    ): array {
        $providerPaymentId = 'FAKE-' . strtoupper(Str::random(16));
        $providerTransactionId = 'FAKE-TX-' . strtoupper(Str::random(14));

        return [
            'payment_method' => $method->code,
            'status' => PaymentIntentStatus::PAID->value,
            'provider_payment_id' => $providerPaymentId,
            'provider_transaction_id' => $providerTransactionId,
            'paid_at' => now(),
            'request_payload' => [
                'provider' => $this->driver(),
                'method' => $method->code,
                'purpose_type' => $intent->purpose_type,
                'purpose_id' => $intent->purpose_id,
                'billing_interval' => $intent->billing_interval,
            ],
            'response_payload' => [
                'provider' => $this->driver(),
                'result' => 'paid',
                'mode' => 'instant_fake',
            ],
            'meta' => [
                'checkout_mode' => 'instant_fake',
            ],
            'transactions' => [
                [
                    'transaction_type' => PaymentTransactionType::INITIATE->value,
                    'status' => PaymentTransactionStatus::INITIATED->value,
                    'provider_payment_id' => $providerPaymentId,
                    'provider_transaction_id' => $providerTransactionId,
                    'provider_reference' => $providerPaymentId,
                    'processed_at' => now(),
                    'response_payload' => ['stage' => 'initialized'],
                ],
                [
                    'transaction_type' => PaymentTransactionType::CHARGE->value,
                    'status' => PaymentTransactionStatus::PAID->value,
                    'provider_payment_id' => $providerPaymentId,
                    'provider_transaction_id' => $providerTransactionId,
                    'provider_reference' => $providerPaymentId,
                    'processed_at' => now(),
                    'response_payload' => ['stage' => 'paid'],
                ],
            ],
        ];
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        return [
            'provider' => $this->driver(),
            'event_type' => (string) ($payload['type'] ?? 'webhook'),
            'status' => strtoupper(trim((string) ($payload['status'] ?? ''))),
            'merchant_transaction_id' => (string) ($payload['merchant_transaction_id'] ?? $payload['merchantTransactionId'] ?? ''),
            'provider_payment_id' => (string) ($payload['payment_id'] ?? $payload['paymentId'] ?? ''),
            'provider_transaction_id' => (string) ($payload['transaction_id'] ?? $payload['uuid'] ?? ''),
            'provider_purchase_id' => (string) ($payload['purchase_id'] ?? $payload['purchaseId'] ?? ''),
            'card_origin' => $this->normalizeCardOrigin($payload['card_origin'] ?? null),
            'normalized_status' => PaymentIntentStatus::PAID->value,
            'raw' => $payload,
        ];
    }
}

<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\AreebaGatewayInterface;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurposeType;
use App\Models\PaymentMethod;

class AreebaPaymentProvider extends AbstractConfiguredPaymentProvider
{
    public function __construct(
        protected AreebaGatewayInterface $gateway,
    ) {
    }

    public function driver(): string
    {
        return 'areeba';
    }

    public function hasValidConfiguration(?PaymentMethod $method = null): bool
    {
        return $this->configurationIssues($method) === [];
    }

    /**
     * @return array<int, string>
     */
    public function configurationIssues(?PaymentMethod $method = null): array
    {
        return $this->gateway->configurationIssues();
    }

    public function recurringStrategy(PaymentMethod $method, PaymentPurposeType $purposeType): string
    {
        if ($purposeType->value === 'credit_product') {
            return 'none';
        }

        if ((bool) config('payments.providers.areeba.schedule_enabled', false)) {
            return 'provider_schedule';
        }

        return (bool) config('payments.providers.areeba.with_register_enabled', false)
            ? 'provider_token'
            : 'manual_renewal';
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        $status = strtoupper(trim((string) (
            data_get($payload, 'status')
            ?? data_get($payload, 'transactionStatus')
            ?? data_get($payload, 'transaction_status')
            ?? ''
        )));

        return [
            'provider' => $this->driver(),
            'event_type' => (string) (
                data_get($payload, 'type')
                ?? data_get($payload, 'eventType')
                ?? data_get($payload, 'transactionType')
                ?? 'webhook'
            ),
            'status' => $status,
            'merchant_transaction_id' => (string) (
                data_get($payload, 'merchantTransactionId')
                ?? data_get($payload, 'merchant_transaction_id')
                ?? data_get($payload, 'merchantReference')
                ?? ''
            ),
            'provider_payment_id' => (string) (
                data_get($payload, 'paymentId')
                ?? data_get($payload, 'payment_id')
                ?? data_get($payload, 'id')
                ?? ''
            ),
            'provider_transaction_id' => (string) (
                data_get($payload, 'uuid')
                ?? data_get($payload, 'transactionId')
                ?? data_get($payload, 'transaction_id')
                ?? ''
            ),
            'provider_purchase_id' => (string) (
                data_get($payload, 'purchaseId')
                ?? data_get($payload, 'purchase_id')
                ?? ''
            ),
            'card_origin' => $this->normalizeCardOrigin(
                data_get($payload, 'card_origin')
                ?? data_get($payload, 'cardOrigin')
                ?? data_get($payload, 'local_or_international')
                ?? data_get($payload, 'returnData.cardData.cardOrigin')
                ?? data_get($payload, 'returnData.cardData.origin')
                ?? null
            ),
            'normalized_status' => match ($status) {
                'PAID', 'SUCCESS', 'APPROVED', 'CAPTURED', 'SETTLED' => PaymentIntentStatus::PAID->value,
                'FAILED', 'DECLINED', 'ERROR' => PaymentIntentStatus::FAILED->value,
                'CANCELLED', 'CANCELED' => PaymentIntentStatus::CANCELED->value,
                'EXPIRED' => PaymentIntentStatus::EXPIRED->value,
                'REFUNDED' => PaymentIntentStatus::REFUNDED->value,
                'AUTHORIZED', 'AUTHORISED' => PaymentIntentStatus::PROCESSING->value,
                default => PaymentIntentStatus::PENDING->value,
            },
            'raw' => $payload,
        ];
    }

    /**
     * @return array<int|string, string>
     */
    protected function requiredConfigKeys(?PaymentMethod $method = null): array
    {
        return [
            'base_url' => 'Base URL',
            'api_key' => 'API Key',
            'username' => 'Username',
            'password' => 'Password',
        ];
    }

    /**
     * Keeps transport configuration in a dedicated config file, mirroring FIB.
     *
     * @return array<string, mixed>
     */
    protected function runtimeConfig(?PaymentMethod $method = null): array
    {
        return array_replace_recursive(
            (array) config('areeba', []),
            is_array($method?->settings) ? $method->settings : [],
        );
    }
}

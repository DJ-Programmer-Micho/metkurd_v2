<?php

namespace App\Services\Payments\Providers;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Fib\FibMapper;
use App\Domain\Payments\Fib\FibWebhookValidator;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurposeType;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class FibPaymentProvider extends AbstractConfiguredPaymentProvider
{
    public function __construct(
        protected FibMapper $mapper,
        protected FibWebhookValidator $validator,
    ) {
    }

    public function driver(): string
    {
        return 'fib';
    }

    public function recurringStrategy(PaymentMethod $method, PaymentPurposeType $purposeType): string
    {
        if ($purposeType->value === 'credit_product') {
            return 'none';
        }

        return 'manual_renewal';
    }

    /**
     * The legacy PaymentIntent-based checkout flow has been retired in favor of
     * the explicit App\Domain\Payments module.
     */
    public function initializeCheckout(
        \App\Models\PaymentIntent $intent,
        PaymentMethod $method,
        \App\Models\Customer $customer,
        array $purpose,
        array $options = [],
    ): array {
        throw new \LogicException('Legacy FIB payment intent checkout is no longer supported. Use App\\Domain\\Payments actions instead.');
    }

    public function synchronizeCheckout(
        \App\Models\PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        throw new \LogicException('Legacy FIB payment intent synchronization is no longer supported. Use App\\Domain\\Payments actions instead.');
    }

    public function cancelCheckout(
        \App\Models\PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        throw new \LogicException('Legacy FIB payment intent cancellation is no longer supported. Use App\\Domain\\Payments actions instead.');
    }

    public function refundCheckout(
        \App\Models\PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        throw new \LogicException('Legacy FIB refunds should be implemented against the explicit App\\Domain\\Payments module.');
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        $status = FibPaymentStatusData::fromArray([
            'paymentId' => (string) ($payload['id'] ?? $payload['paymentId'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'UNPAID'),
            'decliningReason' => $payload['decliningReason'] ?? null,
            'declinedAt' => $payload['declinedAt'] ?? null,
        ]);

        $localStatus = $this->mapper->toLocalStatus($status);

        return [
            'provider' => $this->driver(),
            'event_type' => 'fib_callback',
            'status' => $status->status,
            'merchant_transaction_id' => '',
            'provider_payment_id' => $status->paymentId,
            'provider_transaction_id' => '',
            'provider_purchase_id' => '',
            'card_origin' => $this->normalizeCardOrigin(null),
            'normalized_status' => match ($localStatus->value) {
                'awaiting_customer_action' => PaymentIntentStatus::REQUIRES_ACTION->value,
                'paid' => PaymentIntentStatus::PAID->value,
                'failed' => PaymentIntentStatus::FAILED->value,
                'canceled' => PaymentIntentStatus::CANCELED->value,
                'expired' => PaymentIntentStatus::EXPIRED->value,
                'refund_requested' => PaymentIntentStatus::PROCESSING->value,
                'refunded' => PaymentIntentStatus::REFUNDED->value,
                default => PaymentIntentStatus::PENDING->value,
            },
            'meta' => [
                'provider_status' => $status->status,
                'declining_reason' => $status->decliningReason,
            ],
            'raw' => $payload,
        ];
    }

    public function validateWebhookSignature(Request $request, ?PaymentMethod $method = null): ?bool
    {
        $result = $this->validator->validate($request);

        return $result['issues'] === [] ? null : false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function runtimeConfig(?PaymentMethod $method = null): array
    {
        return array_replace_recursive(
            (array) config('services.fib', []),
            is_array($method?->settings) ? $method->settings : [],
        );
    }

    /**
     * @return array<int|string, string>
     */
    protected function requiredConfigKeys(?PaymentMethod $method = null): array
    {
        return [
            'base_url' => 'Base URL',
            'client_id' => 'Client ID',
            'client_secret' => 'Client Secret',
        ];
    }

    protected function hasCheckoutImplementation(?PaymentMethod $method = null): bool
    {
        return true;
    }
}

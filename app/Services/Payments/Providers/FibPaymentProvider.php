<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\FibGatewayInterface;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurposeType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\Payments\Fib\Data\FibCancelPaymentRequest;
use App\Services\Payments\Fib\Data\FibCreatePaymentRequest;
use App\Services\Payments\Fib\Data\FibPaymentData;
use App\Services\Payments\Fib\Data\FibRefundPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FibPaymentProvider extends AbstractConfiguredPaymentProvider
{
    public function __construct(
        protected FibGatewayInterface $gateway,
    ) {
    }

    public function driver(): string
    {
        return 'fib';
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

    protected function hasCheckoutImplementation(?PaymentMethod $method = null): bool
    {
        return true;
    }

    public function recurringStrategy(PaymentMethod $method, PaymentPurposeType $purposeType): string
    {
        return $purposeType->value === 'credit_product' ? 'none' : 'manual_renewal';
    }

    public function initializeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        Customer $customer,
        array $purpose,
        array $options = [],
    ): array {
        $request = new FibCreatePaymentRequest(
            amount: (int) round((float) $intent->gross_amount_iqd),
            currency: (string) data_get($this->runtimeConfig($method), 'currency', 'IQD'),
            callbackUrl: route('payments.webhooks.fib'),
            description: $this->buildDescription($intent, $purpose),
        );

        $payment = $this->gateway->createPayment($request);

        return $this->mapPaymentToCheckoutResponse(
            intent: $intent,
            method: $method,
            payment: $payment,
            requestPayload: $request->toArray(),
            transactionType: PaymentTransactionType::INITIATE->value,
            transactionStatus: PaymentTransactionStatus::INITIATED->value,
            checkoutMode: 'fib_qr_or_app',
        );
    }

    public function synchronizeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        $paymentId = trim((string) ($intent->provider_payment_id ?? ''));

        if ($paymentId === '') {
            throw new \RuntimeException(__('FIB status sync requires a provider payment id.'));
        }

        $payment = $this->gateway->getPaymentStatus($paymentId);

        return $this->mapPaymentToCheckoutResponse(
            intent: $intent,
            method: $method,
            payment: $payment,
            requestPayload: ['payment_id' => $paymentId, 'source' => $options['source'] ?? 'manual_sync'],
            transactionType: PaymentTransactionType::STATUS_SYNC->value,
            transactionStatus: $this->transactionStatusForIntentStatus(
                $this->normalizeProviderStatus($payment->status, $payment->decliningReason)
            ),
            checkoutMode: 'fib_qr_or_app',
        );
    }

    public function cancelCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        $paymentId = trim((string) ($intent->provider_payment_id ?? ''));

        if ($paymentId === '') {
            throw new \RuntimeException(__('FIB cancel requires a provider payment id.'));
        }

        $request = new FibCancelPaymentRequest(
            paymentId: $paymentId,
            reason: $this->nullableString($options['reason'] ?? null),
            meta: ['source' => $options['source'] ?? 'manual_cancel'],
        );

        $payment = $this->gateway->cancelPayment($request);

        return $this->mapPaymentToCheckoutResponse(
            intent: $intent,
            method: $method,
            payment: $payment,
            requestPayload: $request->toArray(),
            transactionType: PaymentTransactionType::CANCEL->value,
            transactionStatus: PaymentTransactionStatus::CANCELED->value,
            checkoutMode: 'fib_qr_or_app',
        );
    }

    public function refundCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        $paymentId = trim((string) ($intent->provider_payment_id ?? ''));

        if ($paymentId === '') {
            throw new \RuntimeException(__('FIB refund requires a provider payment id.'));
        }

        $request = new FibRefundPaymentRequest(
            paymentId: $paymentId,
            amount: isset($options['amount_iqd']) ? (int) round((float) $options['amount_iqd']) : null,
            reason: $this->nullableString($options['reason'] ?? null),
            meta: ['source' => $options['source'] ?? 'manual_refund'],
        );

        $payment = $this->gateway->refundPayment($request);

        return $this->mapPaymentToCheckoutResponse(
            intent: $intent,
            method: $method,
            payment: $payment,
            requestPayload: $request->toArray(),
            transactionType: PaymentTransactionType::REFUND->value,
            transactionStatus: PaymentTransactionStatus::REFUNDED->value,
            checkoutMode: 'fib_qr_or_app',
        );
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        $status = strtoupper(trim((string) (
            data_get($payload, 'status')
            ?? data_get($payload, 'paymentStatus')
            ?? data_get($payload, 'payment_status')
            ?? ''
        )));

        return [
            'provider' => $this->driver(),
            'event_type' => (string) (data_get($payload, 'type') ?? 'webhook'),
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
                data_get($payload, 'transactionId')
                ?? data_get($payload, 'transaction_id')
                ?? ''
            ),
            'provider_purchase_id' => '',
            'card_origin' => $this->normalizeCardOrigin(null),
            'normalized_status' => $this->normalizeProviderStatus(
                $status,
                (string) (data_get($payload, 'decliningReason') ?? '')
            ),
            'meta' => [
                'provider_status' => $status,
                'checkout_action' => $this->normalizeProviderStatus(
                    $status,
                    (string) (data_get($payload, 'decliningReason') ?? '')
                ) === PaymentIntentStatus::REQUIRES_ACTION->value
                    ? [
                        'readable_code' => $this->nullableString(data_get($payload, 'readableCode')),
                        'qr_code' => $this->nullableString(data_get($payload, 'qrCode')),
                        'links' => array_filter([
                            'personal' => $this->nullableString(data_get($payload, 'personalAppLink')),
                            'business' => $this->nullableString(data_get($payload, 'businessAppLink')),
                            'corporate' => $this->nullableString(data_get($payload, 'corporateAppLink')),
                        ]),
                        'valid_until' => $this->parseProviderDate(data_get($payload, 'validUntil'))?->toIso8601String(),
                    ]
                    : null,
                'declining_reason' => $this->nullableString(data_get($payload, 'decliningReason')),
            ],
            'raw' => $payload,
        ];
    }

    public function validateWebhookSignature(Request $request, ?PaymentMethod $method = null): ?bool
    {
        $config = $this->runtimeConfig($method);
        $secret = trim((string) ($config['callback_secret'] ?? ''));
        $headerName = trim((string) ($config['callback_secret_header'] ?? ''));

        if ($secret === '' || $headerName === '') {
            return null;
        }

        $actual = trim((string) $request->header($headerName, ''));

        if ($actual === '') {
            return false;
        }

        return hash_equals($secret, $actual);
    }

    /**
     * Uses the dedicated fib config as the transport/runtime source of truth.
     *
     * @return array<string, mixed>
     */
    protected function runtimeConfig(?PaymentMethod $method = null): array
    {
        return array_replace_recursive(
            (array) config('fib', []),
            is_array($method?->settings) ? $method->settings : [],
        );
    }

    protected function normalizeProviderStatus(string $status, ?string $decliningReason = null): string
    {
        $status = strtoupper(trim($status));
        $decliningReason = strtoupper(trim((string) $decliningReason));

        if ($status === 'DECLINED') {
            return match ($decliningReason) {
                'PAYMENT_EXPIRATION' => PaymentIntentStatus::EXPIRED->value,
                'PAYMENT_CANCELLATION' => PaymentIntentStatus::CANCELED->value,
                default => PaymentIntentStatus::FAILED->value,
            };
        }

        return match ($status) {
            'PAID' => PaymentIntentStatus::PAID->value,
            'UNPAID' => PaymentIntentStatus::REQUIRES_ACTION->value,
            'CANCELLED', 'CANCELED' => PaymentIntentStatus::CANCELED->value,
            'REFENDED', 'REFUNDED' => PaymentIntentStatus::REFUNDED->value,
            'REFUND_REQUESTED' => PaymentIntentStatus::PROCESSING->value,
            default => PaymentIntentStatus::PROCESSING->value,
        };
    }

    protected function transactionStatusForIntentStatus(string $intentStatus): string
    {
        return match ($intentStatus) {
            PaymentIntentStatus::PAID->value => PaymentTransactionStatus::PAID->value,
            PaymentIntentStatus::FAILED->value, PaymentIntentStatus::EXPIRED->value => PaymentTransactionStatus::FAILED->value,
            PaymentIntentStatus::CANCELED->value => PaymentTransactionStatus::CANCELED->value,
            PaymentIntentStatus::REFUNDED->value => PaymentTransactionStatus::REFUNDED->value,
            default => PaymentTransactionStatus::PENDING->value,
        };
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    protected function mapPaymentToCheckoutResponse(
        PaymentIntent $intent,
        PaymentMethod $method,
        FibPaymentData $payment,
        array $requestPayload,
        string $transactionType,
        string $transactionStatus,
        string $checkoutMode,
    ): array {
        $intentStatus = $this->normalizeProviderStatus($payment->status, $payment->decliningReason);

        return [
            'payment_method' => $method->code,
            'status' => $intentStatus,
            'status_reason' => $payment->decliningReason ?: $payment->status,
            'provider_payment_id' => $payment->paymentId,
            'expires_at' => $payment->validUntil,
            'paid_at' => $intentStatus === PaymentIntentStatus::PAID->value ? ($payment->paidAt ?? now()) : null,
            'failed_at' => $intentStatus === PaymentIntentStatus::FAILED->value ? ($payment->declinedAt ?? now()) : null,
            'canceled_at' => $intentStatus === PaymentIntentStatus::CANCELED->value ? ($payment->cancelledAt ?? now()) : null,
            'expired_at' => $intentStatus === PaymentIntentStatus::EXPIRED->value ? ($payment->declinedAt ?? now()) : null,
            'refunded_at' => $intentStatus === PaymentIntentStatus::REFUNDED->value ? ($payment->refundedAt ?? now()) : null,
            'request_payload' => $requestPayload,
            'response_payload' => $payment->raw,
            'meta' => [
                'provider_status' => $payment->status,
                'declining_reason' => $payment->decliningReason,
                'checkout_mode' => $checkoutMode,
                'checkout_action' => in_array($intentStatus, [
                    PaymentIntentStatus::PENDING->value,
                    PaymentIntentStatus::REQUIRES_ACTION->value,
                    PaymentIntentStatus::PROCESSING->value,
                ], true) ? $this->buildCheckoutAction($intent, $payment) : null,
            ],
            'transactions' => [
                [
                    'transaction_type' => $transactionType,
                    'status' => $transactionStatus,
                    'provider_payment_id' => $payment->paymentId,
                    'provider_reference' => $payment->paymentId,
                    'status_reason' => $payment->status,
                    'processed_at' => now(),
                    'request_payload' => $requestPayload,
                    'response_payload' => $payment->raw,
                    'normalized_payload' => [
                        'provider_status' => $payment->status,
                        'intent_status' => $intentStatus,
                    ],
                    'meta' => [
                        'checkout_mode' => $checkoutMode,
                        'readable_code' => $payment->readableCode,
                    ],
                ],
            ],
        ];
    }

    /**
     * Uses the old working contract style: IQD-first, async, provider-readable description.
     *
     * @param  array<string, mixed>  $purpose
     */
    protected function buildDescription(PaymentIntent $intent, array $purpose): string
    {
        $parts = array_filter([
            'MET KURD',
            strtoupper((string) ($intent->purpose_type ?? 'payment')),
            (string) ($purpose['name'] ?? $intent->purpose_name),
            '[' . (string) $intent->merchant_transaction_id . ']',
        ]);

        return Str::limit(implode(' - ', $parts), 180, '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCheckoutAction(PaymentIntent $intent, FibPaymentData $payment): array
    {
        return [
            'kind' => 'fib_payment',
            'provider' => $this->driver(),
            'intent_uuid' => (string) $intent->uuid,
            'payment_id' => $payment->paymentId,
            'readable_code' => $payment->readableCode,
            'qr_code' => $payment->qrCode,
            'valid_until' => $payment->validUntil?->toIso8601String(),
            'links' => $payment->appLinks(),
            'instructions' => [
                __('Scan the QR code or open a FIB app link to complete this payment.'),
                __('If the callback is delayed, use the status check button after you pay.'),
            ],
        ];
    }

    protected function parseProviderDate(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

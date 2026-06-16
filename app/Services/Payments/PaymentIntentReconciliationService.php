<?php

namespace App\Services\Payments;

use App\Enums\PaymentCardOrigin;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;

class PaymentIntentReconciliationService
{
    public function __construct(
        protected PaymentFeeCalculator $feeCalculator,
        protected PaymentFulfillmentService $fulfillmentService,
    ) {}

    /**
     * @param  array<string, mixed>  $fees
     * @param  array<string, mixed>  $checkout
     */
    public function applyCheckoutResponse(PaymentIntent $intent, array $fees, array $checkout): PaymentIntent
    {
        $intentPayload = array_merge(
            [
                'payment_method' => $intent->payment_method,
                'status' => $intent->status,
                'provider_payment_id' => $intent->provider_payment_id,
                'provider_transaction_id' => $intent->provider_transaction_id,
                'provider_purchase_id' => $intent->provider_purchase_id,
                'provider_customer_ref' => $intent->provider_customer_ref,
                'provider_schedule_ref' => $intent->provider_schedule_ref,
                'card_origin' => $intent->card_origin,
                'expires_at' => $intent->expires_at,
                'authorized_at' => $intent->authorized_at,
                'paid_at' => $intent->paid_at,
                'failed_at' => $intent->failed_at,
                'canceled_at' => $intent->canceled_at,
                'expired_at' => $intent->expired_at,
                'refunded_at' => $intent->refunded_at,
                'request_payload' => $intent->request_payload,
                'response_payload' => $intent->response_payload,
                'meta' => $intent->meta ?? [],
            ],
            collect($checkout)->except(['transactions'])->all(),
        );

        if ((string) ($intentPayload['status'] ?? '') !== PaymentIntentStatus::PENDING->value) {
            $intentPayload['last_status_synced_at'] = now();
        }

        $mergedMeta = array_merge(
            (array) ($intent->meta ?? []),
            (array) ($checkout['meta'] ?? []),
        );

        if (array_key_exists('checkout_action', $mergedMeta) && $mergedMeta['checkout_action'] === null) {
            unset($mergedMeta['checkout_action']);
        }

        $intentPayload['meta'] = $mergedMeta;

        $intent->forceFill($intentPayload)->save();

        foreach ((array) ($checkout['transactions'] ?? []) as $transaction) {
            PaymentTransaction::create([
                'payment_intent_id' => $intent->id,
                'provider' => (string) $intent->provider,
                'transaction_type' => (string) ($transaction['transaction_type'] ?? PaymentTransactionType::INITIATE->value),
                'status' => (string) ($transaction['status'] ?? PaymentTransactionStatus::INITIATED->value),
                'status_reason' => $transaction['status_reason'] ?? null,
                'merchant_transaction_id' => (string) ($transaction['merchant_transaction_id'] ?? $intent->merchant_transaction_id),
                'provider_transaction_id' => $transaction['provider_transaction_id'] ?? $intent->provider_transaction_id,
                'provider_payment_id' => $transaction['provider_payment_id'] ?? $intent->provider_payment_id,
                'provider_purchase_id' => $transaction['provider_purchase_id'] ?? $intent->provider_purchase_id,
                'provider_reference' => $transaction['provider_reference']
                    ?? $intent->provider_payment_id
                    ?? $intent->provider_transaction_id
                    ?? $intent->provider_purchase_id
                    ?? $intent->merchant_transaction_id,
                'amount_iqd' => (int) $fees['base_amount_iqd'],
                'gross_amount_iqd' => (int) $fees['gross_amount_iqd'],
                'surcharge_amount_iqd' => (int) $fees['surcharge_amount_iqd'],
                'provider_fee_amount_iqd' => (int) $fees['provider_fee_amount_iqd'],
                'net_amount_iqd' => (int) $fees['net_amount_iqd'],
                'currency' => 'IQD',
                'card_origin' => $transaction['card_origin'] ?? $intent->card_origin,
                'processed_at' => $transaction['processed_at'] ?? now(),
                'failed_at' => $transaction['failed_at'] ?? null,
                'request_payload' => $transaction['request_payload'] ?? ($checkout['request_payload'] ?? null),
                'response_payload' => $transaction['response_payload'] ?? ($checkout['response_payload'] ?? null),
                'normalized_payload' => $transaction['normalized_payload'] ?? null,
                'meta' => array_merge(
                    ['fee_breakdown' => $fees['fee_breakdown'] ?? []],
                    (array) ($transaction['meta'] ?? []),
                ),
            ]);
        }

        return $intent->fresh(['transactions', 'creditOrders']);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{intent:PaymentIntent,applied:bool,reason:?string}
     */
    public function applyNormalizedUpdate(PaymentIntent $intent, array $normalized): array
    {
        /** @var PaymentIntent $lockedIntent */
        $lockedIntent = PaymentIntent::query()
            ->lockForUpdate()
            ->findOrFail($intent->id);

        $nextStatus = (string) ($normalized['normalized_status'] ?? '');
        $currentStatus = (string) $lockedIntent->status;

        if (! $this->canTransition($currentStatus, $nextStatus)) {
            return [
                'intent' => $lockedIntent,
                'applied' => false,
                'reason' => "Ignored webhook status transition {$currentStatus} -> {$nextStatus}.",
            ];
        }

        $cardOrigin = $normalized['card_origin'] ?? PaymentCardOrigin::UNKNOWN->value;
        $fees = $this->feeCalculator->quote((string) $lockedIntent->payment_method, (int) round((float) $lockedIntent->base_amount_iqd), $cardOrigin);
        $transactionStatus = $this->normalizeTransactionStatus($nextStatus);

        $meta = array_merge(
            (array) ($lockedIntent->meta ?? []),
            (array) ($normalized['meta'] ?? []),
        );

        if (array_key_exists('checkout_action', $meta) && $meta['checkout_action'] === null) {
            unset($meta['checkout_action']);
        }

        $lockedIntent->forceFill(array_filter([
            'status' => $nextStatus,
            'status_reason' => $normalized['status'] ?? null,
            'provider_payment_id' => $normalized['provider_payment_id'] ?: $lockedIntent->provider_payment_id,
            'provider_transaction_id' => $normalized['provider_transaction_id'] ?: $lockedIntent->provider_transaction_id,
            'provider_purchase_id' => $normalized['provider_purchase_id'] ?: $lockedIntent->provider_purchase_id,
            'card_origin' => $cardOrigin,
            'gross_amount_iqd' => $fees['gross_amount_iqd'],
            'surcharge_amount_iqd' => $fees['surcharge_amount_iqd'],
            'provider_fee_amount_iqd' => $fees['provider_fee_amount_iqd'],
            'net_amount_iqd' => $fees['net_amount_iqd'],
            'last_status_synced_at' => now(),
            'authorized_at' => in_array($nextStatus, [PaymentIntentStatus::PROCESSING->value, PaymentIntentStatus::PAID->value], true) ? ($lockedIntent->authorized_at ?? now()) : $lockedIntent->authorized_at,
            'paid_at' => $nextStatus === PaymentIntentStatus::PAID->value ? ($lockedIntent->paid_at ?? now()) : $lockedIntent->paid_at,
            'failed_at' => $nextStatus === PaymentIntentStatus::FAILED->value ? now() : $lockedIntent->failed_at,
            'canceled_at' => $nextStatus === PaymentIntentStatus::CANCELED->value ? now() : $lockedIntent->canceled_at,
            'expired_at' => $nextStatus === PaymentIntentStatus::EXPIRED->value ? now() : $lockedIntent->expired_at,
            'refunded_at' => $nextStatus === PaymentIntentStatus::REFUNDED->value ? now() : $lockedIntent->refunded_at,
            'meta' => $meta,
        ], fn ($value) => $value !== null))->save();

        PaymentTransaction::create([
            'payment_intent_id' => $lockedIntent->id,
            'provider' => (string) $lockedIntent->provider,
            'transaction_type' => (string) ($normalized['transaction_type'] ?? PaymentTransactionType::WEBHOOK->value),
            'status' => $transactionStatus,
            'status_reason' => $normalized['status'] ?? null,
            'merchant_transaction_id' => $lockedIntent->merchant_transaction_id,
            'provider_transaction_id' => $lockedIntent->provider_transaction_id,
            'provider_payment_id' => $lockedIntent->provider_payment_id,
            'provider_purchase_id' => $lockedIntent->provider_purchase_id,
            'provider_reference' => $lockedIntent->provider_payment_id ?: $lockedIntent->provider_transaction_id ?: $lockedIntent->provider_purchase_id,
            'amount_iqd' => (int) round((float) $lockedIntent->base_amount_iqd),
            'gross_amount_iqd' => $fees['gross_amount_iqd'],
            'surcharge_amount_iqd' => $fees['surcharge_amount_iqd'],
            'provider_fee_amount_iqd' => $fees['provider_fee_amount_iqd'],
            'net_amount_iqd' => $fees['net_amount_iqd'],
            'currency' => 'IQD',
            'card_origin' => $cardOrigin,
            'processed_at' => now(),
            'response_payload' => $normalized['raw'] ?? null,
            'normalized_payload' => $normalized,
            'meta' => array_merge(
                ['fee_breakdown' => $fees['fee_breakdown']],
                (array) ($normalized['transaction_meta'] ?? []),
            ),
        ]);

        if ($nextStatus === PaymentIntentStatus::PAID->value) {
            $this->fulfillmentService->fulfill($lockedIntent->fresh());
        }

        return [
            'intent' => $lockedIntent->fresh(['transactions', 'creditOrders']),
            'applied' => true,
            'reason' => null,
        ];
    }

    public function normalizeTransactionStatus(string $intentStatus): string
    {
        return match ($intentStatus) {
            PaymentIntentStatus::PAID->value => PaymentTransactionStatus::PAID->value,
            PaymentIntentStatus::FAILED->value => PaymentTransactionStatus::FAILED->value,
            PaymentIntentStatus::CANCELED->value => PaymentTransactionStatus::CANCELED->value,
            PaymentIntentStatus::REFUNDED->value => PaymentTransactionStatus::REFUNDED->value,
            PaymentIntentStatus::PROCESSING->value, PaymentIntentStatus::REQUIRES_ACTION->value => PaymentTransactionStatus::PROCESSING->value,
            default => PaymentTransactionStatus::PENDING->value,
        };
    }

    public function canTransition(string $currentStatus, string $nextStatus): bool
    {
        if ($currentStatus === $nextStatus) {
            return true;
        }

        return match ($currentStatus) {
            PaymentIntentStatus::PENDING->value => in_array($nextStatus, [
                PaymentIntentStatus::REQUIRES_ACTION->value,
                PaymentIntentStatus::PROCESSING->value,
                PaymentIntentStatus::PAID->value,
                PaymentIntentStatus::FAILED->value,
                PaymentIntentStatus::CANCELED->value,
                PaymentIntentStatus::EXPIRED->value,
            ], true),
            PaymentIntentStatus::REQUIRES_ACTION->value, PaymentIntentStatus::PROCESSING->value => in_array($nextStatus, [
                PaymentIntentStatus::PAID->value,
                PaymentIntentStatus::FAILED->value,
                PaymentIntentStatus::CANCELED->value,
                PaymentIntentStatus::EXPIRED->value,
            ], true),
            PaymentIntentStatus::PAID->value => $nextStatus === PaymentIntentStatus::REFUNDED->value,
            default => false,
        };
    }
}

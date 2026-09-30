<?php

namespace App\Services\Billing;

/** Matches FakePaymentProvider's persisted contract; this is history, never external collection authority. */
final class LegacyFakeIntentEvidence
{
    public static function matches(object $intent): bool
    {
        $response = is_string($intent->response_payload ?? null) ? json_decode($intent->response_payload, true) : ($intent->response_payload ?? []);

        return ($intent->provider ?? null) === 'fake' && ($intent->payment_method ?? null) === 'fake'
            && ($intent->status ?? null) === 'paid' && ! empty($intent->paid_at) && ! empty($intent->fulfilled_at)
            && ($intent->recurring_strategy ?? null) === 'manual_renewal'
            && empty($intent->provider_schedule_ref) && empty($intent->provider_customer_ref)
            && empty($intent->provider_purchase_id) && empty($intent->customer_payment_method_id)
            && preg_match('/^FAKE-[A-Za-z0-9]{16}$/D', (string) ($intent->provider_payment_id ?? '')) === 1
            && preg_match('/^FAKE-TX-[A-Za-z0-9]{14}$/D', (string) ($intent->provider_transaction_id ?? '')) === 1
            && ($response['provider'] ?? null) === 'fake' && ($response['mode'] ?? null) === 'instant_fake';
    }

    public static function orderMatches(object $order, object $intent): bool
    {
        return self::matches($intent) && (int) ($order->payment_intent_id ?? 0) === (int) $intent->id
            && (int) $order->customer_id === (int) $intent->customer_id && empty($order->payment_id)
            && $order->provider === 'fake' && ($order->payment_method ?? null) === 'fake'
            && $order->status === 'paid' && $order->source_type === $intent->purpose_type
            && in_array($intent->purpose_type, ['service_plan', 'storage_plan'], true)
            && (int) ($order->{$intent->purpose_type.'_id'} ?? 0) === (int) $intent->purpose_id;
    }
}

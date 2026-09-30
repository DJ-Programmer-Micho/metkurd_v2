<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;

/** Creation evidence only; current configuration and later HTTP failures prove no provenance. */
final class FibProviderProvenance
{
    public function classify(Payment $payment, array $creationEvents): array
    {
        $unknown = ['classification' => 'unknown_environment', 'evidence_event_id' => null, 'creation_host' => null];
        if ($payment->provider?->value !== 'fib' || ! $payment->isProviderSubscriptionObject()
            || ! $payment->fib_subscription_id || count($creationEvents) !== 1
            || $payment->isRevenueExcluded() || data_get($payment->meta, 'mock') || data_get($payment->meta, 'synthetic')) {
            return $unknown;
        }
        /** @var PaymentEvent $event */
        $event = $creationEvents[0];
        $response = $payment->create_response;
        $request = $payment->create_payload;
        if (! is_array($response) || ! is_array($request) || ! $request
            || (int) $event->payment_id !== (int) $payment->id || $event->provider !== 'fib'
            || $event->event_type !== 'provider_subscription_created' || $event->source !== 'customer_checkout'
            || $event->provider_object_type !== 'subscription' || $event->fib_subscription_id !== $payment->fib_subscription_id
            || ! $payment->local_reference || $event->local_reference !== $payment->local_reference
            || ($response['subscriptionId'] ?? null) !== $payment->fib_subscription_id
            || ProviderReviewSnapshot::hash($event->payload) !== ProviderReviewSnapshot::hash($response)
            || ProviderReviewSnapshot::hash(data_get($event->meta, 'create_payload')) !== ProviderReviewSnapshot::hash($request)
            || ! $event->created_at || ! $payment->created_at || $event->created_at->lt($payment->created_at)
            || $event->created_at->isFuture()) {
            return $unknown;
        }
        // These are FIB-returned checkout links, not merchant references or callback URLs.
        // Exact HTTPS hosts only. Never publish the private path/query/QR code.
        $url = is_string($response['appLink'] ?? null) ? parse_url($response['appLink']) : false;
        if (! $url || ($url['scheme'] ?? null) !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            return $unknown;
        }
        $classification = match ($url['host'] ?? null) {
            'p-stage.fib.iq' => 'confirmed_test_or_staging',
            'p.fib.iq' => 'confirmed_production',
            default => 'unknown_environment',
        };
        if ($classification === 'unknown_environment'
            || (data_get($payment->provider_links, 'app') !== null && data_get($payment->provider_links, 'app') !== $response['appLink'])) {
            return $unknown;
        }

        return ['classification' => $classification, 'evidence_event_id' => (int) $event->id, 'creation_host' => $url['host']];
    }
}

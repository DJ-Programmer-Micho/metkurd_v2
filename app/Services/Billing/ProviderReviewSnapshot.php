<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Support\FibStatusEvidence;
use Illuminate\Support\Facades\DB;

/** One bulk snapshot, not one query per obligation. Raw evidence never leaves this service. */
class ProviderReviewSnapshot
{
    public array $rows = [];

    public array $fingerprints = [];

    private array $events = [];

    private array $callbacks = [];

    private array $eventHashes = [];

    private array $collectionEvidence = [];

    public static function hash(mixed $value): string
    {
        $canonical = function ($item) use (&$canonical) {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($canonical, $item);
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }

    public static function capture(bool $lock = false, ?\Illuminate\Database\Connection $db = null): self
    {
        $db ??= DB::connection();
        $self = new self;
        foreach (['payments', 'customer_service_subscriptions', 'customer_storage_subscriptions', 'credit_orders',
            'subscription_credit_allocations', 'payment_intents', 'provider_coverage_dispositions', 'provider_obligation_reviews'] as $table) {
            if (str_starts_with($table, 'provider_') && ! $db->getSchemaBuilder()->hasTable($table)) {
                $self->rows[$table] = [];
                $self->fingerprints[$table] = 'missing';

                continue;
            }
            $data = $db->table($table)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
            $self->rows[$table] = $data->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
            $self->fingerprints[$table] = self::hash($self->rows[$table]);
        }
        $hashes = [];
        foreach ($db->table('payment_events')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->cursor() as $event) {
            $hash = self::hash((array) $event);
            $hashes[] = $hash;
            $self->eventHashes[$event->payment_id][] = $hash;
            $payload = json_decode($event->payload ?? '{}', true);
            if (in_array($event->before_status, ['paid', 'refunded', 'refund_requested'], true)
                || in_array($event->after_status, ['paid', 'refunded', 'refund_requested'], true)
                || collect(['lastPaymentAt', 'lastPaidAt', 'activeUntil', 'lastSuccessfulPaymentAt', 'latestPaidAt',
                    'payment.lastPaymentAt', 'payment.lastPaidAt', 'latestPayment.lastPaymentAt', 'latestPayment.lastPaidAt',
                    'subscription.lastPaymentAt', 'subscription.lastPaidAt'])
                    ->contains(fn ($key) => data_get($payload, $key) !== null)) {
                $self->collectionEvidence[$event->payment_id] = true;
            }
            if (in_array($event->event_type, ['provider_status_checked', 'provider_status_ignored'], true)) {
                $self->events[$event->payment_id] = (new PaymentEvent)->newFromBuilder((array) $event);
            }
            if ($event->event_type === 'callback_received') {
                $self->callbacks[$event->payment_id] = $event->id;
            }
        }
        $self->fingerprints['payment_events'] = self::hash($hashes);
        // Completed approval provenance is loaded in bulk, without claiming its mutable audit table as business evidence.
        $ids = array_unique(array_column($self->rows['provider_obligation_reviews'], 'operation_id'));
        $self->rows['admin_operations'] = $db->table('admin_operations')->whereIn('id', $ids)->get()->mapWithKeys(fn ($r) => [$r->id => (array) $r])->all();

        return $self;
    }

    public function payment(int $id): ?Payment
    {
        return isset($this->rows['payments'][$id]) ? (new Payment)->newFromBuilder($this->rows['payments'][$id]) : null;
    }

    public function observation(Payment $payment): ?array
    {
        $event = $this->events[$payment->id] ?? null;

        return (new FibStatusEvidence)->validatePersistedObservation($payment, $event,
            ($this->callbacks[$payment->id] ?? 0) > ($event?->id ?? 0));
    }

    public function subscriptions(Payment $payment): array
    {
        $result = [];
        foreach (['service', 'storage'] as $kind) {
            foreach ($this->rows['customer_'.$kind.'_subscriptions'] as $row) {
                if ((int) ($row['payment_id'] ?? 0) === (int) $payment->id
                    || ($payment->fib_subscription_id && ($row['provider_ref'] ?? null) === $payment->fib_subscription_id)) {
                    $result[] = ['kind' => $kind, 'row' => $row];
                }
            }
        }

        return $result;
    }

    public function basis(Payment $payment): string
    {
        return self::hash([$payment->getRawOriginal(), $this->subscriptions($payment), $this->eventHashes[$payment->id] ?? [],
            array_values(array_filter($this->rows['credit_orders'], fn ($r) => (int) ($r['payment_id'] ?? 0) === (int) $payment->id)),
            array_values(array_filter($this->rows['subscription_credit_allocations'], fn ($r) => (int) ($r['payment_id'] ?? 0) === (int) $payment->id))]);
    }

    public function unpaidUnbound(Payment $payment): bool
    {
        if ($payment->provider?->value !== 'fib' || ! $payment->isProviderSubscriptionObject() || ! $payment->fib_subscription_id
            || ! in_array($payment->status?->value, ['pending', 'awaiting_customer_action', 'failed', 'canceled', 'expired'], true)
            || $payment->isFulfilled() || $payment->paid_at || $payment->last_payment_at || $payment->active_until
            || isset($this->collectionEvidence[$payment->id])
            || ! in_array($payment->internal_status?->value, ['pending', 'awaiting_customer_action', 'failed', 'canceled', 'expired'], true)
            || $payment->review_required_at || $payment->requiresReview() || data_get($payment->meta, 'provider_evidence_rejection')
            || $this->subscriptions($payment)) {
            return false;
        }
        foreach ($this->rows['provider_obligation_reviews'] as $review) {
            $evidence = json_decode($review['evidence'] ?? '{}', true);
            if ((int) $review['original_payment_id'] === (int) $payment->id
                && (! empty($evidence['last_payment_at']) || ! empty($evidence['active_until']))) {
                // A later empty terminal reply cannot erase collection observed by an earlier batch.
                return false;
            }
        }
        foreach (['credit_orders', 'subscription_credit_allocations'] as $table) {
            foreach ($this->rows[$table] as $row) {
                if ((int) ($row['payment_id'] ?? 0) === (int) $payment->id) {
                    return false;
                }
            }
        }
        foreach (['verified_subscription_collection', 'provider_cancellation.effective_access_until',
            'provider_cancellation.observed_active_until', 'provider_cancellation.retained_active_until'] as $key) {
            if (data_get($payment->meta, $key)) {
                return false;
            }
        }

        return true;
    }

    public function hasStatusObservation(Payment $payment): bool
    {
        return isset($this->events[$payment->id]);
    }

    public function remoteEligible(Payment $payment): bool
    {
        if ($payment->provider?->value !== 'fib' || ! $payment->isProviderSubscriptionObject()
            || ! preg_match('/^[a-zA-Z0-9_-]{1,190}$/D', (string) $payment->fib_subscription_id)
            || $payment->review_required_at || $payment->requiresReview() || $payment->status?->value === 'refund_requested'
            || data_get($payment->meta, 'provider_evidence_rejection')
            || ($payment->status?->value === 'paid' && (! $payment->paid_at || ! $payment->isFulfilled()))
            || $payment->isRevenueExcluded() || data_get($payment->meta, 'mock') || data_get($payment->meta, 'synthetic')) {
            return false;
        }
        // A provider object must identify exactly one local Payment before any request.
        if (count(array_filter($this->rows['payments'], fn ($r) => $r['fib_subscription_id'] === $payment->fib_subscription_id)) !== 1) {
            return false;
        }
        foreach ($this->subscriptions($payment) as $sub) {
            $row = $sub['row'];
            $meta = json_decode($row['meta'] ?? '{}', true);
            if ((int) $row['customer_id'] !== (int) $payment->customer_id || (int) $row['payment_id'] !== (int) $payment->id
                || collect(array_filter([$row['provider_ref'], $meta['fib_subscription_id'] ?? null, $meta['provider_ref'] ?? null]))
                    ->contains(fn ($ref) => $ref !== $payment->fib_subscription_id)) {
                return false;
            }
        }

        return true;
    }

    public function matchedManualHistory(array $row, string $kind): bool
    {
        $meta = json_decode($row['meta'] ?? '{}', true);
        $order = $this->rows['credit_orders'][$meta['order_id'] ?? 0] ?? null;
        if ($kind !== 'service' || $row['source'] !== 'admin_manual' || ($meta['provider'] ?? null) !== 'admin_manual'
            || $row['payment_id'] !== null || empty($row['provider_ref']) || ! empty($meta['payment_id'])
            || ! empty($meta['fib_subscription_id']) || ! empty($meta['provider_ref'])
            || in_array($meta['billing_source'] ?? null, ['fib', 'areeba'], true) || ! $order
            || $order['provider'] !== 'admin_manual' || $order['payment_method'] !== 'admin_manual'
            || $order['payment_id'] !== null || $order['payment_intent_id'] !== null
            || (int) $order['customer_id'] !== (int) $row['customer_id']
            || (int) $order['service_plan_id'] !== (int) $row['service_plan_id'] || $order['provider_ref'] !== $row['provider_ref']) {
            return false;
        }

        return ! collect($this->rows['payments'])->contains(fn ($p) => $p['fib_subscription_id'] === $row['provider_ref'] || $p['fib_payment_id'] === $row['provider_ref']);
    }
}

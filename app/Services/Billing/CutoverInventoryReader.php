<?php

namespace App\Services\Billing;

use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** Audit projection only. No models, locks, transport, container services or mutations. */
final class CutoverInventoryReader
{
    public function __construct(private Connection $db) {}

    private function table(string $name): Builder
    {
        return $this->db->table($name)->useWritePdo(); // Same PDO as the read-only snapshot, never a lagging read replica.
    }

    public function inspect(bool $details = false): array
    {
        $report = ['counts' => [], 'wallets' => [], 'subscriptions' => [], 'payments' => [], 'blockers' => []];
        foreach (['customers', 'payments', 'payment_events', 'payment_intents', 'payment_transactions', 'payment_webhook_events',
            'credit_orders', 'coupon_redemptions', 'customer_service_subscriptions', 'customer_storage_subscriptions',
            'subscription_credit_allocations', 'credit_ledgers'] as $table) {
            // A missing table/column throws; never report a missing table as empty.
            $report['counts'][$table] = $this->table($table)->count();
        }
        $report['counts']['fully_verified_customers'] = $this->table('customers')->where('email_verify', 1)->where('phone_verify', 1)->count();
        $wallets = $this->table('credit_wallets')->get(['customer_id', 'wallet_type', 'balance_credits', 'subscription_balance_credits', 'addon_balance_credits']);
        foreach (['app', 'api'] as $type) {
            $rows = $wallets->where('wallet_type', $type);
            $report['wallets'][$type] = ['count' => $rows->count(), 'balance' => $rows->sum('balance_credits'),
                'subscription' => $rows->sum('subscription_balance_credits'), 'addon' => $rows->sum('addon_balance_credits')];
        }
        $payments = $this->table('payments')->get(['id', 'customer_id', 'provider', 'provider_object_type', 'purchasable_type', 'purchasable_id',
            'status', 'internal_status', 'fulfilled_at', 'fib_subscription_id', 'provider_subscription_status', 'active_until', 'last_payment_at', 'meta'])->keyBy('id');
        $intents = $this->table('payment_intents')->get();
        $orders = $this->table('credit_orders')->get();
        $paymentGroups = [];
        foreach ($payments as $payment) {
            $labels = [];
            if (in_array($payment->status, ['pending', 'awaiting_customer_action'], true)) {
                $labels[] = 'pending';
            }
            if ($payment->internal_status === 'requires_review') {
                $labels[] = 'requires_review';
            }
            if ($payment->status === 'paid') {
                $labels[] = $payment->fulfilled_at ? 'paid_fulfilled' : 'paid_unfulfilled';
            }
            if (in_array($payment->status, ['failed', 'canceled', 'cancelled', 'expired', 'refunded'], true)) {
                $labels[] = $payment->status === 'cancelled' ? 'canceled' : $payment->status;
            }
            if (! in_array($payment->status, ['paid', 'failed', 'canceled', 'cancelled', 'expired', 'refunded'], true)
                || ! in_array($payment->internal_status, ['applied', 'failed', 'canceled', 'expired', 'refunded'], true)
                || ($payment->status === 'paid' && ! $payment->fulfilled_at)
                || ($payment->fulfilled_at && ! in_array($payment->status, ['paid', 'refunded'], true))) {
                $labels[] = 'unresolved';
            }
            if ($payment->fib_subscription_id) {
                $labels[] = 'provider_subscription_references';
            }
            foreach ($labels as $label) {
                $paymentGroups[$label][] = $payment;
            }
        }
        foreach (['unresolved', 'pending', 'requires_review', 'paid_fulfilled', 'paid_unfulfilled', 'failed', 'canceled', 'expired', 'refunded', 'provider_subscription_references'] as $label) {
            $rows = collect($paymentGroups[$label] ?? []);
            $report['payments'][$label] = ['rows' => $rows->count(), 'customers' => $rows->pluck('customer_id')->unique()->count()];
        }
        if ($report['payments']['unresolved']['rows']) {
            $report['blockers'][] = 'unresolved_payments';
        }
        $retainedIntents = $intents->filter(fn ($p) => LegacyFakeIntentEvidence::matches($p))->keyBy('id');
        $report['retained_financial_legacy'] = ['intent_ids' => $retainedIntents->keys()->all(),
            'order_ids' => $orders->filter(fn ($o) => isset($retainedIntents[$o->payment_intent_id]) && LegacyFakeIntentEvidence::orderMatches($o, $retainedIntents[$o->payment_intent_id]))->pluck('id')->all()];
        $unresolvedIntents = $intents->reject(fn ($p) => isset($retainedIntents[$p->id]))->filter(fn ($p) => ! in_array($p->status, ['paid', 'failed', 'canceled', 'expired', 'refunded'], true)
            || $p->status === 'paid' || $p->provider_schedule_ref);
        $report['legacy_intents'] = ['unresolved_or_scheduled' => $unresolvedIntents->count(), 'customers' => $unresolvedIntents->pluck('customer_id')->unique()->count()];
        if ($unresolvedIntents->isNotEmpty()) {
            $report['blockers'][] = 'unresolved_legacy_intents_or_schedule';
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $linked = [];
        foreach (['service', 'storage'] as $domain) {
            $planKey = $domain.'_plan_id';
            $plans = $this->table($domain.'_plans')->get()->keyBy('id');
            $free = $domain === 'service'
                ? $this->table('service_plans')->where('is_active', 1)->where(fn ($q) => $q->where('code', 'free')->orWhere('is_free', 1))
                    ->orderByRaw("CASE WHEN code = 'free' THEN 0 ELSE 1 END")->orderBy('sort_order')->orderBy('id')->first()
                : $this->table('storage_plans')->where('is_active', 1)->where(fn ($q) => $q->where('code', 'free-512')->orWhere('price_iqd', 0)->orWhere('price_usd', 0))
                    ->orderByRaw("CASE WHEN code = 'free-512' THEN 0 ELSE 1 END")->orderBy('quota_mb')->orderBy('sort_order')->orderBy('id')->first();
            if (! $free) {
                $report['blockers'][] = $domain.'_free_plan_unavailable';
            }
            $rows = $this->table('customer_'.$domain.'_subscriptions')->orderBy('id')->get(['id', 'customer_id', $planKey, 'payment_id', 'status', 'source', 'renewal_strategy', 'auto_renew', 'provider_ref', 'starts_at', 'ends_at', 'cycle_ends_on', 'next_renewal_on', 'meta']);
            $latest = $rows->groupBy('customer_id')->map(fn ($group) => $group->last()->id);
            // Same eligible-row selection as CustomerBillingStateService; audit does not mutate cached customer relations.
            $effective = $rows->filter(fn ($s) => $s->status === 'active'
                && (! $s->starts_at || ($this->date($s->starts_at)?->lte($now) ?? false))
                && (! $s->ends_at || ($this->date($s->ends_at)?->gte($now) ?? false)))
                ->groupBy('customer_id')->map(fn ($group) => $group->last()->id);
            $classified = [];
            foreach ($rows as $s) {
                $p = $payments->get($s->payment_id);
                if ($p) {
                    $linked[$p->id] = true;
                }
                $plan = $plans->get($s->{$planKey});
                $meta = $this->meta($s->meta);
                $provider = (bool) ($s->payment_id || $s->provider_ref || in_array($s->source, ['fib', 'areeba'], true)
                    || in_array($s->renewal_strategy, ['provider_schedule', 'provider_token'], true)
                    || ! empty($meta['fib_subscription_id']) || ! empty($meta['provider_ref'])
                    || in_array($meta['billing_source'] ?? null, ['fib', 'areeba'], true));
                $selected = ($effective[$s->customer_id] ?? null) === $s->id;
                $superseded = ! empty($meta['superseded_at']) || ($latest[$s->customer_id] ?? null) !== $s->id;
                $manual = in_array($s->source, ['admin_manual_grant', 'admin_manual', 'admin', 'manual', 'internal_non_revenue'], true);
                $manualEnd = $this->date($s->ends_at) ?? $this->manualBoundary($s, $meta);
                $class = 'unknown';
                $reason = 'unclassified_origin_or_term';
                $end = $this->date($s->ends_at);
                if (! $plan || ($s->starts_at && ! $this->date($s->starts_at)) || ($s->ends_at && ! $end)) {
                    $reason = 'missing_plan_or_invalid_dates';
                } elseif ($provider) {
                    $boundary = $p ? $this->verifiedBoundary($p) : null;
                    $bound = $p && (int) $p->customer_id === (int) $s->customer_id && (int) $p->purchasable_id === (int) $plan->id
                        && $p->purchasable_type === 'App\\Models\\'.ucfirst($domain).'Plan'
                        && (! $s->provider_ref || $s->provider_ref === $p->fib_subscription_id);
                    if (! $bound || ! $boundary) {
                        $reason = 'missing_or_unverified_collection_binding_or_paid_through';
                    } elseif ($boundary->gt($now)) {
                        $class = 'provider_paid_looking';
                        $reason = $superseded ? 'superseded_but_verified_unexpired_paid_term' : 'verified_unexpired_paid_term';
                    } elseif (in_array(strtoupper((string) $p->provider_subscription_status), ['EXPIRED', 'CANCELED', 'CANCELLED', 'ENDED', 'INACTIVE', 'TIMED_OUT', 'FAILED', 'DECLINED', 'REJECTED'], true)) {
                        $class = 'expired_stale';
                        $reason = 'verified_paid_term_elapsed';
                    } else {
                        $reason = 'elapsed_coverage_but_provider_state_unresolved';
                    }
                } elseif (($domain === 'service' && $plan->is_free) || ($domain === 'storage' && $plan->id === $free?->id)) {
                    $class = 'free';
                    $reason = $selected ? 'effective_free' : 'historical_free';
                } elseif ($manual && ! in_array($meta['revenue_record'] ?? null, [true, 1, '1', 'true'], true)
                    && ! $s->auto_renew && $s->renewal_strategy === 'manual_renewal' && $manualEnd) {
                    if ($selected && $superseded && $manualEnd->gte($now)) {
                        $reason = 'runtime_and_supersession_disagree';
                    } elseif ($manualEnd->lt($now) || $superseded || in_array($s->status, ['ended', 'expired', 'canceled', 'cancelled'], true)) {
                        $class = 'expired_stale';
                        $reason = 'manual_term_ended_or_superseded';
                    } elseif ($selected) {
                        $class = 'complimentary_manual';
                        $reason = 'effective_explicit_manual_term';
                    }
                }
                $classified[] = ['customer_id' => $s->customer_id, 'subscription_id' => $s->id, 'payment_id' => $s->payment_id,
                    'plan_code' => $this->token($plan?->code), 'status' => $this->token($s->status),
                    'starts_at' => $this->date($s->starts_at)?->toIso8601String(), 'ends_at' => $end?->toIso8601String(),
                    'provider_status' => $this->token($p?->provider_subscription_status),
                    'classification' => $class, 'reason' => $reason, 'runtime_effective' => $selected,
                    'nonfree_plan' => $plan && ($domain === 'service' ? ! $plan->is_free : $plan->id !== $free?->id),
                    'superseded' => $superseded, 'provider_related' => $provider];
            }
            foreach (['free', 'complimentary_manual', 'provider_paid_looking', 'expired_stale', 'unknown'] as $class) {
                $group = collect($classified)->where('classification', $class);
                $report['subscriptions'][$domain][$class] = ['rows' => $group->count(), 'customers' => $group->pluck('customer_id')->unique()->count(),
                    'effective_rows' => $group->where('runtime_effective', true)->count()];
            }
            $report['subscriptions'][$domain]['stale_provider_rows'] = collect($classified)->where('provider_related', true)->where('classification', 'expired_stale')->count();
            $report['subscriptions'][$domain]['effective_nonfree_provider_rows'] = collect($classified)->where('provider_related', true)->where('runtime_effective', true)->where('nonfree_plan', true)->count();
            $report['subscriptions'][$domain]['free_fallback_customers'] = $free ? $this->table('customers')->whereNotIn('id', $effective->keys()->all())->count() : null;
            if (collect($classified)->contains(fn ($r) => in_array($r['classification'], ['unknown', 'provider_paid_looking'], true))) {
                $report['blockers'][] = $domain.'_current_or_unknown_obligations';
            }
            if ($details) {
                $report['details'][$domain] = $classified;
            }
        }
        // Detached paid plan purchases and provider objects still matter even if the customer resolves to Free.
        $unlinked = $payments->filter(fn ($p) => ! isset($linked[$p->id]) && ($p->fib_subscription_id
            || ($p->status === 'paid' && in_array($p->purchasable_type, ['App\\Models\\ServicePlan', 'App\\Models\\StoragePlan'], true))));
        $report['payments']['unlinked_plan_or_provider_evidence'] = ['rows' => $unlinked->count(), 'customers' => $unlinked->pluck('customer_id')->unique()->count()];
        if ($unlinked->isNotEmpty()) {
            $report['blockers'][] = 'unlinked_plan_or_provider_evidence';
        }
        if ($details) {
            $report['details']['payments'] = $payments->map(fn ($p) => ['payment_id' => $p->id, 'customer_id' => $p->customer_id,
                'status' => $this->token($p->status), 'internal_status' => $this->token($p->internal_status),
                'provider_status' => $this->token($p->provider_subscription_status)])->values()->all();
        }
        $paidOrders = $orders->filter(fn ($o) => $o->status === 'paid' && ! $this->nonRevenue($o));
        $unlinkedOrders = $paidOrders->filter(fn ($o) => ($o->order_type === 'subscription' || in_array($o->source_type, ['service_plan', 'storage_plan'], true))
            && ! isset($linked[$o->payment_id]) && ! in_array($o->id, $report['retained_financial_legacy']['order_ids'], true));
        $report['unlinked_paid_plan_orders'] = ['rows' => $unlinkedOrders->count(), 'customers' => $unlinkedOrders->pluck('customer_id')->unique()->count()];
        if ($unlinkedOrders->isNotEmpty()) {
            $report['blockers'][] = 'unlinked_paid_plan_orders';
        }
        $buyers = $payments->where('status', 'paid')->pluck('customer_id')->merge($intents->where('status', 'paid')->pluck('customer_id'))->merge($paidOrders->pluck('customer_id'))->unique();
        $addonBuyers = $paidOrders->filter(fn ($o) => $o->order_type === 'addon' || $o->source_type === 'credit_product')->pluck('customer_id')->unique();
        foreach (['app', 'api'] as $type) {
            $rows = $wallets->where('wallet_type', $type);
            $report['credit_dependency'][$type] = [
                'historical_paid_record_customers_with_credits' => $rows->whereIn('customer_id', $buyers)->where('balance_credits', '>', 0)->pluck('customer_id')->unique()->count(),
                'addon_purchase_customers_with_addon_balance' => $rows->whereIn('customer_id', $addonBuyers)->where('addon_balance_credits', '>', 0)->pluck('customer_id')->unique()->count(),
            ];
        }
        $report['credit_dependency']['note'] = 'Historical paid records are provenance, not proof of exact unspent attribution; explicit manual/non-revenue orders excluded.';

        return $report;
    }

    private function nonRevenue(object $order): bool
    {
        $meta = $this->meta($order->meta);

        return in_array($order->provider, ['admin_manual', 'admin_manual_grant', 'internal_non_revenue'], true)
            || in_array($meta['billing_source'] ?? null, ['admin_manual', 'admin_manual_grant', 'internal_non_revenue'], true)
            || in_array($meta['revenue_record'] ?? null, [false, 0, '0', 'false'], true)
            || in_array($meta['revenue_excluded'] ?? null, [true, 1, '1', 'true'], true);
    }

    /** Non-provider term hints used by SubscriptionCyclePolicy; never use these as provider receipt evidence. */
    private function manualBoundary(object $subscription, array $meta): ?CarbonImmutable
    {
        $raw = $meta['period_ends_at'] ?? null;
        if ($raw !== null) {
            $end = FibSubscriptionTimestamp::parse($raw);

            return $end ? CarbonImmutable::instance($end) : null;
        }
        foreach (['cycle_ends_on', 'next_renewal_on'] as $key) {
            if ($subscription->{$key}) {
                $value = $subscription->{$key};

                return $this->date(is_string($value) && strlen($value) === 10 ? $value.' 00:00:00' : $value)?->endOfDay();
            }
        }

        return null;
    }

    /** Conservative audit of the existing Phase 1/2 durable verified collection descriptor. */
    private function verifiedBoundary(object $p): ?CarbonImmutable
    {
        $evidence = $this->meta($p->meta)['verified_subscription_collection'] ?? null;
        if ($p->provider !== 'fib' || $p->provider_object_type !== 'subscription' || $p->status !== 'paid' || ! $p->fulfilled_at || ! is_array($evidence)
            || ! $p->fib_subscription_id || ($evidence['provider_object_id'] ?? null) !== $p->fib_subscription_id) {
            return null;
        }
        $end = FibSubscriptionTimestamp::parse($evidence['paid_through'] ?? null);
        $last = FibSubscriptionTimestamp::parse($evidence['last_payment_at'] ?? null);
        $storedEnd = $this->date($p->active_until);
        $storedLast = $this->date($p->last_payment_at);
        if (! $end || ! $last || ! $storedEnd || ! $storedLast || ! $end->gt($last) || $last->isFuture()
            || $end->timestamp !== $storedEnd->timestamp || $last->timestamp !== $storedLast->timestamp) {
            return null;
        }

        return CarbonImmutable::instance($end);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value, config('app.timezone'));

            return $date->format('Y-m-d H:i:s') === substr($value, 0, 19) ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function meta(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function token(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $value) ? $value : null;
    }
}

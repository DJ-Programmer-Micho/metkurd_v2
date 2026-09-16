<?php

namespace App\Services\Billing\Cutover;

use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\FibStatusEvidence;
use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use Illuminate\Support\Facades\DB;

/** Reads persisted evidence only. Never invokes cancellation, polling or fulfillment. */
class ProviderObligationInventory
{
    public function inspect(string $target, array $normalizations): array
    {
        $items = [];
        $payments = Payment::orderBy('id')->get()->keyBy('id');
        $linked = [];
        foreach ($normalizations as $table => $rows) {
            foreach ($rows as $normalization) {
                $row = DB::table($table)->find($normalization['id']);
                $payment = $payments[$row->payment_id] ?? null;
                $meta = json_decode($row->meta ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                foreach ([$row->ends_at, $meta['period_ends_at'] ?? null, $meta['provider_active_until'] ?? null] as $raw) {
                    if ($raw !== null && (! ($end = FibSubscriptionTimestamp::parse($raw)) || $end->isFuture())) {
                        $items[] = ['table' => $table, 'id' => $row->id, 'customer_id' => $row->customer_id,
                            'classification' => 'requires_operator_review', 'reason' => 'retained_subscription_coverage_needs_disposition'];
                        break;
                    }
                }
                if ($payment && (int) $payment->customer_id === (int) $row->customer_id) {
                    $linked[$payment->id][] = ['table' => $table, 'id' => $row->id];
                    $references = array_filter([$row->provider_ref, $meta['fib_subscription_id'] ?? null, $meta['provider_ref'] ?? null]);
                    if (collect($references)->contains(fn ($reference) => $reference !== $payment->fib_subscription_id)) {
                        $items[] = ['table' => $table, 'id' => $row->id, 'customer_id' => $row->customer_id,
                            'classification' => 'unresolved_remote_obligation', 'reason' => 'conflicting_provider_reference'];
                    }
                } else {
                    // A provider reference surviving without an owned Payment cannot prove cancellation.
                    $items[] = ['table' => $table, 'id' => $row->id, 'customer_id' => $row->customer_id,
                        'classification' => 'unresolved_remote_obligation', 'reason' => 'missing_owned_provider_evidence'];
                }
            }
        }
        foreach ($payments as $payment) {
            if (! isset($linked[$payment->id]) && ! $payment->isProviderSubscriptionObject()
                && ! $payment->fib_subscription_id && $payment->payment_mode?->value !== 'recurring'
                && ! $payment->active_until?->isFuture()) {
                continue;
            }
            [$classification, $reason] = $this->classify($payment);
            $items[] = ['payment_id' => $payment->id, 'customer_id' => $payment->customer_id,
                'subscriptions' => $linked[$payment->id] ?? [], 'classification' => $classification, 'reason' => $reason];
        }
        // Legacy provider schedules can exist without a native Payment or normalized
        // subscription. No current evidence service proves their remote retirement.
        foreach (DB::table('payment_intents')->where(fn ($q) => $q->where('is_recurring', true)
            ->orWhereNotNull('provider_schedule_ref')->orWhereIn('recurring_strategy', ['provider_schedule', 'provider_token']))
            ->orderBy('id')->get(['id', 'customer_id']) as $intent) {
            $items[] = ['table' => 'payment_intents', 'id' => $intent->id, 'customer_id' => $intent->customer_id,
                'classification' => 'unresolved_remote_obligation', 'reason' => 'legacy_recurring_intent_requires_provider_disposition'];
        }
        $summary = array_fill_keys(['retired_confirmed_cancelled', 'valid_coverage_to_preserve', 'requires_operator_review', 'unresolved_remote_obligation'], 0);
        foreach ($items as $item) {
            $summary[$item['classification']]++;
        }
        $blockers = [];
        if ($target === 'production') {
            foreach ($items as $item) {
                if ($item['classification'] !== 'retired_confirmed_cancelled') {
                    $blockers[] = 'Provider obligation '.($item['payment_id'] ?? $item['table'].':'.$item['id']).': '.$item['classification'].' ('.$item['reason'].').';
                }
            }
        }

        return ['policy' => $target === 'production' ? 'confirmed_disposition_required' : 'disposable_copy_no_remote_effect',
            'summary' => $summary, 'items' => $items, 'blockers' => $blockers];
    }

    private function classify(Payment $payment): array
    {
        $context = (array) data_get($payment->meta, 'provider_cancellation', []);
        $ends = [];
        foreach ([$payment->active_until, data_get($payment->meta, 'verified_subscription_collection.paid_through'),
            $context['effective_access_until'] ?? null, $context['observed_active_until'] ?? null] as $raw) {
            if ($raw !== null) {
                $end = FibSubscriptionTimestamp::parse($raw instanceof \DateTimeInterface ? $raw->format(DATE_ATOM) : $raw);
                if (! $end) {
                    return ['requires_operator_review', 'ambiguous_paid_coverage'];
                }
                $ends[] = $end;
            }
        }
        $hasCollection = $payment->paid_at || $payment->last_payment_at || $payment->isFulfilled();
        if (collect($ends)->contains(fn ($end) => $end->isFuture())) {
            // Shared cutover retires provider authority. Do not erase still-valid coverage;
            // a separate approved preservation/disposition phase must resolve it first.
            return $hasCollection ? ['valid_coverage_to_preserve', 'paid_through_boundary_not_finished']
                : ['requires_operator_review', 'coverage_without_collection_evidence'];
        }
        if ($payment->review_required_at || $payment->requiresReview()
            || $payment->status?->value === 'refund_requested'
            || ($payment->paid_at && ! $payment->isFulfilled() && $payment->status?->value === 'paid')
            || data_get($payment->meta, 'provider_evidence_rejection')) {
            return ['requires_operator_review', 'financial_review_unresolved'];
        }
        $confirmedAt = FibSubscriptionTimestamp::parse($context['provider_cancel_confirmed_at'] ?? null);
        $terminal = in_array(strtoupper((string) $payment->provider_subscription_status), ['CANCELED', 'CANCELLED'], true);
        $contextMatches = ! isset($context['provider_ref']) || $context['provider_ref'] === $payment->fib_subscription_id;
        $hasContext = $terminal && ($context['state'] ?? null) === 'confirmed'
            && ($context['provider_cancel_pending'] ?? true) === false && $confirmedAt && ! $confirmedAt->isFuture() && $contextMatches;
        // Both paths are written by the current lifecycle only after a validated authenticated GET:
        // cancellation service result, or observation of the stored validated status response.
        $verified = $hasContext && in_array($context['result'] ?? null, ['already_canceled', 'already_scheduled'], true);
        if ($hasContext && ! $verified && $payment->last_status_checked_at && $payment->status_response) {
            $status = FibSubscriptionStatusData::fromArray((array) $payment->status_response);
            $verified = in_array($status->status, ['CANCELED', 'CANCELLED'], true)
                && app(FibStatusEvidence::class)->rejection($payment, $status) === null;
        }
        if (! $verified || $payment->provider?->value !== 'fib' || ! $payment->fib_subscription_id) {
            return ['unresolved_remote_obligation', 'no_authenticated_cancellation_confirmation'];
        }
        if ($hasCollection && ! $ends) {
            return ['requires_operator_review', 'paid_coverage_boundary_missing'];
        }

        return ['retired_confirmed_cancelled', 'confirmed_renewal_stop_and_no_remaining_coverage'];
    }
}

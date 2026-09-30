<?php

namespace App\Services\Billing\Cutover;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Services\Billing\ProviderSubscriptionCancellation;
use Illuminate\Support\Facades\DB;

/** Reads persisted evidence only. Never invokes cancellation, polling or fulfillment. */
class ProviderObligationInventory
{
    public function inspect(string $target, array $normalizations): array
    {
        $items = [];
        $payments = Payment::orderBy('id')->get()->keyBy('id');
        $approved = [];
        foreach ($payments as $payment) {
            if ($disposition = app(\App\Services\Billing\ProviderCoverageDispositions::class)->approvedFor($payment)) {
                $approved[$payment->id] = $disposition;
            }
        }
        $snapshot = \App\Services\Billing\ProviderReviewSnapshot::capture();
        $retirement = new \App\Services\Billing\ProviderRetirementEvidence;
        $linked = [];
        foreach ($normalizations as $table => $rows) {
            foreach ($rows as $normalization) {
                $row = DB::table($table)->find($normalization['id']);
                $payment = $payments[$row->payment_id] ?? null;
                $meta = json_decode($row->meta ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                foreach ([$row->ends_at, $meta['period_ends_at'] ?? null, $meta['provider_active_until'] ?? null] as $raw) {
                    if (! isset($approved[$row->payment_id]) && $raw !== null && (! ($end = FibSubscriptionTimestamp::parse($raw)) || $end->isFuture())) {
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
            $confirmation = app(ProviderSubscriptionCancellation::class)->confirmation($payment, $snapshot);
            $terminal = $retirement->terminal($snapshot, $payment);
            $provenance = $snapshot->provenance($payment);
            [$classification, $reason] = isset($approved[$payment->id])
                ? ['approved_coverage_to_preserve', 'audited_bounded_term_and_confirmed_renewal_stop']
                : ($terminal ? ['retired_confirmed_cancelled', $terminal['kind']] : $this->classify($payment, $confirmation, $provenance));
            $items[] = ['payment_id' => $payment->id, 'customer_id' => $payment->customer_id,
                'subscriptions' => $linked[$payment->id] ?? [], 'classification' => $classification, 'reason' => $reason,
                'cancellation_evidence' => $confirmation, 'retirement_evidence' => $terminal, 'provider_provenance' => $provenance];
        }
        // Legacy provider schedules can exist without a native Payment or normalized
        // subscription. No current evidence service proves their remote retirement.
        foreach (DB::table('payment_intents')->where(fn ($q) => $q->where('is_recurring', true)
            ->orWhereNotNull('provider_schedule_ref')->orWhereIn('recurring_strategy', ['provider_schedule', 'provider_token']))
            ->orderBy('id')->get() as $intent) {
            $items[] = ['table' => 'payment_intents', 'id' => $intent->id, 'customer_id' => $intent->customer_id,
                'classification' => \App\Services\Billing\LegacyFakeIntentEvidence::matches($intent) ? 'financial_legacy_preserved' : 'unresolved_remote_obligation',
                'reason' => \App\Services\Billing\LegacyFakeIntentEvidence::matches($intent) ? 'fake_manual_without_remote_schedule' : 'legacy_recurring_intent_requires_provider_disposition'];
        }
        $summary = array_fill_keys(['approved_coverage_to_preserve', 'financial_legacy_preserved', 'retired_confirmed_cancelled', 'nonproduction_provider_history', 'valid_coverage_to_preserve', 'requires_operator_review', 'unresolved_remote_obligation'], 0);
        foreach ($items as $item) {
            $summary[$item['classification']]++;
        }
        $blockers = [];
        if ($target === 'production') {
            foreach ($items as $item) {
                if (! in_array($item['classification'], ['retired_confirmed_cancelled', 'nonproduction_provider_history', 'approved_coverage_to_preserve', 'financial_legacy_preserved'], true)) {
                    $blockers[] = 'Provider obligation '.($item['payment_id'] ?? $item['table'].':'.$item['id']).': '.$item['classification'].' ('.$item['reason'].').';
                }
            }
        }

        return ['policy' => $target === 'production' ? 'confirmed_disposition_required' : 'disposable_copy_no_remote_effect',
            'summary' => $summary, 'items' => $items, 'blockers' => $blockers];
    }

    public function classify(Payment $payment, array $confirmation, array $provenance = []): array
    {
        $context = (array) data_get($payment->meta, 'provider_cancellation', []);
        $ends = [];
        foreach ([$payment->active_until, data_get($payment->meta, 'verified_subscription_collection.paid_through'),
            $context['effective_access_until'] ?? null, $context['observed_active_until'] ?? null,
            $context['retained_active_until'] ?? null,
            $confirmation['observed_active_until']] as $raw) {
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
            return $hasCollection ? ['valid_coverage_to_preserve', $confirmation['confirmed']
                ? 'confirmed_renewal_stop_with_remaining_coverage' : 'paid_through_boundary_not_finished']
                : ['requires_operator_review', 'coverage_without_collection_evidence'];
        }
        if ($payment->review_required_at || $payment->requiresReview()
            || $payment->status?->value === 'refund_requested'
            || ($payment->paid_at && ! $payment->isFulfilled() && $payment->status?->value === 'paid')
            || data_get($payment->meta, 'provider_evidence_rejection')) {
            return ['requires_operator_review', 'financial_review_unresolved'];
        }
        $testProvider = ($provenance['classification'] ?? null) === 'confirmed_test_or_staging';
        if (! $confirmation['confirmed'] && ! $testProvider) {
            return ['unresolved_remote_obligation', 'no_authenticated_cancellation_confirmation'];
        }
        if ($hasCollection && ! $ends) {
            return ['requires_operator_review', 'paid_coverage_boundary_missing'];
        }

        return $testProvider
            ? ['nonproduction_provider_history', 'creation_evidence_proves_staging_not_production_cancellation']
            : ['retired_confirmed_cancelled', 'confirmed_renewal_stop_and_no_remaining_coverage'];
    }
}

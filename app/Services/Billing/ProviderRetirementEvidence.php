<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;

/** Retirement evidence stops renewal; paid access requires separate coverage authority. */
class ProviderRetirementEvidence
{
    public function terminal(ProviderReviewSnapshot $snapshot, Payment $payment): ?array
    {
        if (! $snapshot->unpaidUnbound($payment)) {
            return null;
        }
        $reviews = array_values(array_filter($snapshot->rows['provider_obligation_reviews'],
            fn ($r) => (int) $r['original_payment_id'] === (int) $payment->id));
        if ($reviews) {
            $row = $this->latest($snapshot, $payment);
            if (! $row || $row['outcome'] !== 'confirmed_retired') {
                return null;
            }

            return ['kind' => $row['kind'], 'evidence_event_id' => $row['evidence_event_id'], 'review_id' => $row['id']];
        }
        $observation = $snapshot->observation($payment);
        $status = $observation['status'] ?? null;
        // These documented subscription states are narrower than legacy display aliases.
        if ($status && in_array($status->status, ['CANCELLED', 'CANCELED', 'REJECTED'], true)
            && ! $status->lastPaymentAt && ! $status->activeUntil) {
            return ['kind' => 'persisted_authenticated_get', 'evidence_event_id' => $observation['event_id']];
        }

        return null;
    }

    public function latest(ProviderReviewSnapshot $snapshot, Payment $payment): ?array
    {
        $reviews = array_values(array_filter($snapshot->rows['provider_obligation_reviews'],
            fn ($r) => (int) $r['original_payment_id'] === (int) $payment->id));
        if ($reviews) {
            $row = end($reviews);
            $operation = $snapshot->rows['admin_operations'][$row['operation_id']] ?? null;
            $result = json_decode($operation['result'] ?? '{}', true);
            if ($row['provider'] !== 'fib'
                || (int) $row['customer_id'] !== (int) $payment->customer_id || $row['provider_object_id'] !== $payment->fib_subscription_id
                || ! hash_equals($row['basis_hash'], $snapshot->basis($payment)) || ($operation['status'] ?? null) !== 'completed'
                || ! in_array($operation['action'] ?? null, [ProviderObligationBatchActions::ACTION, ProviderRemoteRetirementBatch::RESULT], true)
                || (int) ($operation['admin_id'] ?? 0) !== (int) $row['admin_id']
                || ! hash_equals((string) ($result['evidence_hashes'][$row['id']] ?? ''), self::approvalHash($row))) {
                return null;
            }

            return $row;
        }

        return null;
    }

    public function renewalConfirmation(Payment $payment, ?ProviderReviewSnapshot $snapshot = null): ?array
    {
        if (! $snapshot && (! \Illuminate\Support\Facades\Schema::hasTable('provider_obligation_reviews')
            || ! \Illuminate\Support\Facades\DB::table('provider_obligation_reviews')->where('original_payment_id', $payment->id)->where('kind', 'remote_retirement')->exists())) {
            return null;
        }
        $snapshot ??= ProviderReviewSnapshot::capture();
        if (! collect($snapshot->rows['provider_obligation_reviews'])->contains(fn ($r) => (int) $r['original_payment_id'] === (int) $payment->id && $r['kind'] === 'remote_retirement')) {
            return null;
        }
        // Once remote review starts, older local cancellation evidence cannot bypass
        // a pending, failed, changed or contradictory authenticated observation.
        $blocked = ['confirmed' => false, 'evidence_event_id' => null,
            'observed_active_until' => null, 'observed_last_payment_at' => null];
        $row = $this->latest($snapshot, $payment);
        if (! $row || $row['kind'] !== 'remote_retirement' || ! in_array($row['outcome'], ['renewal_stopped', 'confirmed_retired'], true)
            || ! in_array($row['provider_status'], ['CANCELLED', 'CANCELED', 'REJECTED'], true)) {
            return $blocked;
        }
        $evidence = json_decode($row['evidence'], true);
        if (! ($evidence['verified'] ?? false)) {
            return $blocked;
        }

        return ['confirmed' => true, 'evidence_event_id' => (int) $row['evidence_event_id'],
            'observed_active_until' => $evidence['active_until'] ?? null, 'observed_last_payment_at' => $evidence['last_payment_at'] ?? null,
            'remote_review_id' => (int) $row['id']];
    }

    public static function approvalHash(array $row): string
    {
        unset($row['id'], $row['created_at'], $row['updated_at']);
        $row['evidence'] = is_string($row['evidence']) ? json_decode($row['evidence'], true, flags: JSON_THROW_ON_ERROR) : $row['evidence'];
        foreach (['original_payment_id', 'customer_id', 'admin_id', 'evidence_event_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return ProviderReviewSnapshot::hash($row);
    }
}

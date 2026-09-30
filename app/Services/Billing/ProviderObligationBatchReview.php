<?php

namespace App\Services\Billing;

use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Services\Billing\Cutover\CutoverIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ProviderObligationBatchReview
{
    public const CATEGORIES = ['confirmed_retired', 'paid_coverage', 'draft_unpaid', 'active_trial', 'conflict', 'retained_fake_history', 'unresolved'];

    public function review(string $target): array
    {
        \App\Support\Admin\AdminAccess::authorize('admin.finance');
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');
        $identity = app(CutoverIdentity::class)->inspect($target);
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET TRANSACTION READ ONLY');
        }

        return DB::transaction(fn () => $this->manifest(ProviderReviewSnapshot::capture(), $identity, now()->toIso8601String()));
    }

    public function manifest(ProviderReviewSnapshot $snapshot, array $identity, string $reviewedAt): array
    {
        $at = CarbonImmutable::parse($reviewedAt);
        $items = [];
        $boundSubscriptions = [];
        foreach ($snapshot->rows['payments'] as $id => $raw) {
            $payment = $snapshot->payment($id);
            $subscriptions = $snapshot->subscriptions($payment);
            if (! $payment->fib_subscription_id && ! $payment->fib_payment_id && ! $payment->isProviderSubscriptionObject()
                && $payment->payment_mode?->value !== 'recurring' && ! $subscriptions) {
                continue;
            }
            foreach ($subscriptions as $sub) {
                $boundSubscriptions[$sub['kind'].':'.$sub['row']['id']] = true;
            }
            $observation = $snapshot->observation($payment);
            $status = $observation['status'] ?? null;
            $terminal = app(ProviderRetirementEvidence::class)->terminal($snapshot, $payment);
            $latest = app(ProviderRetirementEvidence::class)->latest($snapshot, $payment);
            $hasReview = collect($snapshot->rows['provider_obligation_reviews'])->contains(fn ($r) => (int) $r['original_payment_id'] === (int) $payment->id);
            $providerStatus = $latest ? $latest['provider_status'] : ($status?->status ?? $payment->provider_subscription_status);
            $reviewEvidence = $latest ? json_decode($latest['evidence'], true) : [];
            if ($latest && ($reviewEvidence['verified'] ?? false)) {
                $status = \App\Domain\Payments\Data\FibSubscriptionStatusData::fromArray([
                    'id' => $payment->fib_subscription_id, 'status' => $providerStatus,
                    'lastPaymentAt' => $reviewEvidence['last_payment_at'] ?? null, 'activeUntil' => $reviewEvidence['active_until'] ?? null,
                ]);
            } elseif (($latest['kind'] ?? null) === 'remote_retirement') {
                $status = null;
            }
            $category = 'unresolved';
            $reason = 'no_authenticated_retirement_evidence';
            $objectId = $payment->fib_subscription_id ?: $payment->fib_payment_id;
            $safeObject = is_string($objectId) && preg_match('/^[a-zA-Z0-9_-]{1,190}$/D', $objectId);
            $conflictReason = match (true) {
                ! $safeObject => 'missing_or_unsafe_provider_identity',
                (bool) ($payment->review_required_at || $payment->requiresReview()) => 'financial_review_required',
                (bool) data_get($payment->meta, 'provider_evidence_rejection') => 'provider_evidence_previously_rejected',
                $snapshot->hasStatusObservation($payment) && ! $observation && ! $latest => 'persisted_get_not_current_or_valid',
                default => null,
            };
            $conflict = $conflictReason !== null;
            foreach ($subscriptions as $sub) {
                $row = $sub['row'];
                $meta = json_decode($row['meta'] ?? '{}', true);
                $mismatch = (int) $row['customer_id'] !== (int) $payment->customer_id
                    || (int) ($row['payment_id'] ?? 0) !== (int) $payment->id
                    || collect(array_filter([$row['provider_ref'], $meta['fib_subscription_id'] ?? null, $meta['provider_ref'] ?? null]))
                        ->contains(fn ($ref) => $ref !== $payment->fib_subscription_id);
                if ($mismatch) {
                    $conflict = true;
                    $conflictReason = 'subscription_identity_conflict';
                }
                foreach ([$row['ends_at'], $meta['period_ends_at'] ?? null, $meta['provider_active_until'] ?? null] as $date) {
                    if ($date !== null && ! FibSubscriptionTimestamp::parse($date)) {
                        $conflict = true;
                        $conflictReason = 'malformed_subscription_boundary';
                    }
                }
            }
            if ($terminal) {
                [$category, $reason] = ['confirmed_retired', $terminal['kind']];
            } elseif ($conflict) {
                [$category, $reason] = ['conflict', $conflictReason];
            } elseif ($hasReview && ! $latest) {
                [$category, $reason] = ['conflict', 'review_evidence_changed_refresh_required'];
            } elseif ($payment->paid_at || $payment->isFulfilled() || $payment->last_payment_at || $status?->lastPaymentAt || ($reviewEvidence['last_payment_at'] ?? null)) {
                [$category, $reason] = ['paid_coverage', 'paid_term_requires_individual_review'];
                $renewalStopped = $status && (in_array($status->status, ['CANCELLED', 'CANCELED'], true)
                    || ($status->status === 'REJECTED' && ($latest['kind'] ?? null) === 'remote_retirement' && ($reviewEvidence['verified'] ?? false)));
                if ($renewalStopped && $status->activeUntil && $status->activeUntil->gt($at)) {
                    $reason = 'confirmed_renewal_stop_operator_interval_approval_required';
                }
                if ($renewalStopped) {
                    [$oldClass] = app(\App\Services\Billing\Cutover\ProviderObligationInventory::class)->classify($payment,
                        ['confirmed' => true, 'observed_active_until' => $status->activeUntil?->format('Y-m-d\TH:i:s.vP')]);
                    $remaining = collect($subscriptions)->contains(fn ($s) => collect([$s['row']['ends_at'], data_get(json_decode($s['row']['meta'] ?? '{}', true), 'period_ends_at'),
                        data_get(json_decode($s['row']['meta'] ?? '{}', true), 'provider_active_until')])->contains(fn ($raw) => $raw !== null && (! ($end = FibSubscriptionTimestamp::parse($raw)) || $end->gt($at))));
                    if ($oldClass === 'retired_confirmed_cancelled' && ! $remaining) {
                        [$category, $reason] = ['confirmed_retired', 'confirmed_renewal_stop_and_no_remaining_coverage'];
                    }
                }
            } elseif (in_array($providerStatus, ['ACTIVE', 'TRIAL'], true)) {
                [$category, $reason] = ['active_trial', 'remote_renewal_or_activation_unresolved'];
            } elseif ($providerStatus === 'DRAFT' && $snapshot->unpaidUnbound($payment)) {
                [$category, $reason] = ['draft_unpaid', 'draft_and_checkout_expiry_do_not_prove_retirement'];
            }
            $disposition = collect($snapshot->rows['provider_coverage_dispositions'])->first(fn ($r) => (int) $r['original_payment_id'] === (int) $id);
            if ($disposition && $category === 'paid_coverage') {
                $reason = 'existing_disposition_requires_cutover_revalidation';
            }
            $item = ['key' => 'payment:'.$id, 'payment_id' => $id, 'customer_id' => $payment->customer_id,
                'existing_disposition_id' => $disposition['id'] ?? null,
                'subscriptions' => array_map(fn ($s) => ['kind' => $s['kind'], 'id' => $s['row']['id']], $subscriptions),
                'provider' => $payment->provider?->value, 'provider_object_id' => $safeObject ? $objectId : null,
                'local_reference' => preg_match('/^[a-zA-Z0-9_-]{1,190}$/D', (string) $payment->local_reference) ? $payment->local_reference : null, 'local_status' => $payment->status?->value,
                'internal_status' => $payment->internal_status?->value, 'provider_status' => preg_match('/^[A-Z_]{1,30}$/D', (string) $providerStatus) ? $providerStatus : null,
                'created_at' => $payment->created_at?->toIso8601String(), 'paid_at' => $payment->paid_at?->toIso8601String(),
                'recent_paid' => ($payment->paid_at && $payment->paid_at->gte($at->subDays(7)))
                    || ($status?->lastPaymentAt && $status->lastPaymentAt->gte($at->subDays(7)))
                    || ($payment->last_payment_at && $payment->last_payment_at->gte($at->subDays(7))),
                'paid' => (bool) ($payment->paid_at || $payment->last_payment_at || $payment->isFulfilled()),
                'applied' => $payment->isFulfilled(), 'verified_start' => $status?->lastPaymentAt?->format('Y-m-d\TH:i:s.vP'),
                'verified_end' => $status?->activeUntil?->format('Y-m-d\TH:i:s.vP'), 'evidence_event_id' => $latest['evidence_event_id'] ?? $terminal['evidence_event_id'] ?? $observation['event_id'] ?? null,
                'category' => $category, 'reason' => $reason, 'operator_action_required' => $category !== 'confirmed_retired',
                'proposed_actions' => match ($category) {
                    'draft_unpaid' => $latest && $latest['outcome'] === 'unresolved'
                        && in_array($latest['kind'], ['draft_get', 'remote_retirement'], true)
                        && in_array($latest['provider_status'], [null, 'DRAFT'], true)
                        && in_array($reviewEvidence['reason'] ?? null, ['provider_unavailable', 'verified_observation', 'draft_not_contractually_cancellable'], true)
                        && empty($reviewEvidence['last_payment_at']) && empty($reviewEvidence['active_until'])
                            ? ['draft_get', 'merchant_attestation'] : ['draft_get'],
                    'paid_coverage' => $reason === 'confirmed_renewal_stop_operator_interval_approval_required' && count($subscriptions) === 1 ? ['coverage_approval'] : [],
                    default => (! $conflict || $conflictReason === 'persisted_get_not_current_or_valid') && $category !== 'confirmed_retired' && $category !== 'active_trial'
                        && $payment->provider_subscription_status === 'DRAFT' && $snapshot->unpaidUnbound($payment) ? ['draft_get'] : [],
                }];
            $priorRemote = collect($snapshot->rows['provider_obligation_reviews'])->contains(fn ($r) => (int) $r['original_payment_id'] === (int) $id && $r['kind'] === 'remote_retirement');
            $item['remote_review_eligible'] = ! $disposition && $snapshot->remoteEligible($payment);
            $item['remote_previously_reviewed'] = $priorRemote;
            if ($item['remote_review_eligible']) {
                $item['proposed_actions'][] = 'remote_retire';
            }
            $items[] = $item;
        }
        foreach (['service', 'storage'] as $kind) {
            foreach ($snapshot->rows['customer_'.$kind.'_subscriptions'] as $row) {
                if (isset($boundSubscriptions[$kind.':'.$row['id']])) {
                    continue;
                }
                $meta = json_decode($row['meta'] ?? '{}', true);
                $provider = $row['payment_id'] || $row['provider_ref'] || in_array($row['source'], ['fib', 'areeba', 'provider', 'online'], true)
                    || ! empty($meta['fib_subscription_id']) || ! empty($meta['provider_ref']) || ! empty($meta['payment_id'])
                    || in_array($row['renewal_strategy'], ['provider_schedule', 'provider_token'], true);
                if (! $provider) {
                    continue; // Ordinary Free and manual rows are not provider work.
                }
                $manual = $snapshot->matchedManualHistory($row, $kind);
                $items[] = ['key' => $kind.':'.$row['id'], 'customer_id' => $row['customer_id'],
                    'subscriptions' => [['kind' => $kind, 'id' => $row['id']]], 'category' => $manual ? 'retained_fake_history' : 'conflict',
                    'reason' => $manual ? 'matched_legacy_manual_order' : 'orphan_or_unmatched_provider_subscription', 'operator_action_required' => ! $manual, 'proposed_actions' => []];
            }
        }
        foreach ($snapshot->rows['payment_intents'] as $row) {
            $fake = LegacyFakeIntentEvidence::matches((object) $row);
            $remote = ! empty($row['provider_payment_id']) || ! empty($row['provider_transaction_id'])
                || ! empty($row['provider_customer_ref']) || ! empty($row['provider_purchase_id']) || ! empty($row['customer_payment_method_id']);
            if (! $fake && ! $remote && ! $row['is_recurring'] && ! $row['provider_schedule_ref'] && ! in_array($row['recurring_strategy'], ['provider_schedule', 'provider_token'], true)) {
                continue;
            }
            $items[] = ['key' => 'intent:'.$row['id'], 'intent_id' => $row['id'], 'customer_id' => $row['customer_id'],
                'category' => $fake ? 'retained_fake_history' : 'unresolved',
                'reason' => $fake ? 'fake_manual_history_no_remote_schedule' : 'legacy_remote_identity_requires_separate_evidence',
                'operator_action_required' => ! $fake, 'proposed_actions' => []];
        }
        usort($items, fn ($a, $b) => strcmp($a['key'], $b['key']));
        $review = ['version' => 1, 'identity' => $identity, 'code' => $this->code(), 'reviewed_at' => $reviewedAt,
            'fingerprints' => $snapshot->fingerprints, 'counts' => array_replace(array_fill_keys(self::CATEGORIES, 0), array_count_values(array_column($items, 'category'))),
            'items' => $items];
        $review['manifest_hash'] = ProviderReviewSnapshot::hash($review);

        return ['review' => $review, 'decisions' => []];
    }

    public function validate(array $packet, bool $lock = false): ProviderReviewSnapshot
    {
        $review = $packet['review'] ?? [];
        $at = FibSubscriptionTimestamp::parse($review['reviewed_at'] ?? null);
        if (! $at || $at->isFuture() || ! is_array($packet['decisions'] ?? null)) {
            throw new PaymentHistoryResetRefused('Invalid review packet.');
        }
        $identity = app(CutoverIdentity::class)->inspect($review['identity']['target'] ?? '');
        $snapshot = ProviderReviewSnapshot::capture($lock);
        $fresh = $this->manifest($snapshot, $identity, $review['reviewed_at'])['review'];
        if (! hash_equals(ProviderReviewSnapshot::hash($fresh), ProviderReviewSnapshot::hash($review))) {
            throw new PaymentHistoryResetRefused('Review identity, source revision or evidence changed; export a fresh manifest.');
        }

        return $snapshot;
    }

    public function worksheet(array $packet): array
    {
        foreach ($packet['review']['items'] as $item) {
            if (in_array('coverage_approval', $item['proposed_actions'], true)) {
                $packet['decisions'][] = ['action' => 'coverage_approval', 'selected' => false,
                    'payment_id' => $item['payment_id'], 'customer_id' => $item['customer_id'], 'provider_subscription_id' => $item['provider_object_id'],
                    'subscription_kind' => $item['subscriptions'][0]['kind'], 'subscription_id' => $item['subscriptions'][0]['id'],
                    'evidence_event_id' => $item['evidence_event_id'], 'coverage_start' => $item['verified_start'], 'coverage_end' => $item['verified_end'],
                    'coverage_confirmed' => false, 'review_reference' => ''];
            }
            if (in_array('draft_get', $item['proposed_actions'], true)) {
                $packet['decisions'][] = ['action' => 'draft_get', 'selected' => false, 'payment_id' => $item['payment_id'],
                    'customer_id' => $item['customer_id'], 'provider_subscription_id' => $item['provider_object_id']];
            }
            if (in_array('merchant_attestation', $item['proposed_actions'], true)) {
                $packet['decisions'][] = ['action' => 'merchant_attestation', 'selected' => false, 'payment_id' => $item['payment_id'],
                    'customer_id' => $item['customer_id'], 'provider_subscription_id' => $item['provider_object_id'],
                    'disposition' => '', 'review_reference' => '', 'evidence_sha256' => '', 'observed_at' => '',
                    'attested' => false, 'no_collection' => false, 'irreversibly_non_activatable' => false];
            }
        }

        return $packet;
    }

    /** Select a deterministic next group without editing customer/provider IDs. */
    public function selectRemote(array $packet, int $limit, bool $retryReviewed = false): array
    {
        if ($limit < 1 || $limit > 25) {
            throw new PaymentHistoryResetRefused('Remote retirement requires 1–25 objects.');
        }
        $items = array_values(array_filter($packet['review']['items'], fn ($i) => ($i['remote_review_eligible'] ?? false)
            && ($retryReviewed || ! $i['remote_previously_reviewed'])));
        usort($items, fn ($a, $b) => $a['payment_id'] <=> $b['payment_id']);
        $packet['decisions'] = array_map(fn ($i) => ['action' => 'remote_retire', 'selected' => true,
            'payment_id' => $i['payment_id'], 'customer_id' => $i['customer_id'], 'provider_subscription_id' => $i['provider_object_id']], array_slice($items, 0, $limit));

        return $packet;
    }

    /** Prepare a new worksheet only. A returned spreadsheet/status list is never execution authority. */
    public function importMerchant(array $packet, array $returned): array
    {
        if (($returned['manifest_hash'] ?? null) !== $packet['review']['manifest_hash'] || ! is_array($returned['items'] ?? null) || ! $returned['items']) {
            throw new PaymentHistoryResetRefused('Merchant review must bind the source manifest.');
        }
        $seen = [];
        foreach ($returned['items'] as $item) {
            $id = $item['payment_id'] ?? null;
            if (! is_int($id) || isset($seen[$id]) || array_diff(array_keys($item), ['payment_id', 'provider_subscription_id',
                'disposition', 'review_reference', 'evidence_sha256', 'observed_at', 'attested', 'no_collection', 'irreversibly_non_activatable'])) {
                throw new PaymentHistoryResetRefused('Invalid, duplicate or unexpected merchant review fields.');
            }
            $matched = false;
            foreach ($packet['decisions'] as &$decision) {
                if ($decision['action'] === 'merchant_attestation' && $decision['payment_id'] === $id
                    && $decision['provider_subscription_id'] === ($item['provider_subscription_id'] ?? null)) {
                    $decision = array_replace($decision, $item, ['selected' => true]);
                    $matched = true;
                }
            }
            unset($decision);
            if (! $matched) {
                throw new PaymentHistoryResetRefused('Merchant object does not match an eligible manifest item.');
            }
            $seen[$id] = true;
        }

        return $packet;
    }

    public function merchantExport(array $packet): array
    {
        return ['manifest_hash' => $packet['review']['manifest_hash'], 'items' => array_values(array_map(
            fn ($i) => array_intersect_key($i, array_flip(['payment_id', 'provider_object_id', 'local_reference', 'local_status', 'provider_status', 'created_at', 'paid'])),
            array_filter($packet['review']['items'], fn ($i) => isset($i['payment_id']) && $i['operator_action_required'] && $i['provider'] === 'fib')))];
    }

    public function code(): array
    {
        $revision = config('provider_obligation_review.release_revision');
        if (! $revision) {
            $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $process->setTimeout(5)->mustRun();
            $revision = trim($process->getOutput());
        }
        if (! preg_match('/^[a-f0-9]{40}$/D', $revision)) {
            throw new PaymentHistoryResetRefused('A release revision is required.');
        }
        $files = [];
        foreach (['app', 'config', 'database/migrations'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        $files['composer.lock'] = hash_file('sha256', base_path('composer.lock'));

        return ['revision' => $revision, 'source_hash' => ProviderReviewSnapshot::hash($files)];
    }
}

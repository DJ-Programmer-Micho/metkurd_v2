<?php

namespace App\Services\Billing;

use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\AdminOperation;
use App\Services\Admin\AdminOperationRunner;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** External POST cannot roll back. Commit preparation and the per-object POST fence before HTTP. */
class ProviderRemoteRetirementBatch
{
    public const PREPARE = 'billing.provider_obligations.remote_prepare';

    public const RESULT = 'billing.provider_obligations.remote_result';

    public function apply(array $packet, string $operation, string $reason, ?string $hash, bool $execute): array
    {
        $admin = $this->authorize();
        $review = app(ProviderObligationBatchReview::class);
        $identity = app(Cutover\CutoverIdentity::class)->inspect($packet['review']['identity']['target'] ?? '');
        if (ProviderReviewSnapshot::hash($identity) !== ProviderReviewSnapshot::hash($packet['review']['identity'])
            || $review->code() !== $packet['review']['code']) {
            throw new PaymentHistoryResetRefused('Remote review identity or source changed.');
        }
        $selected = $this->selected($packet);
        $packetHash = ProviderReviewSnapshot::hash($packet);
        if (! $execute) {
            $review->validate($packet);

            return ['review_hash' => $packetHash, 'selected' => count($selected), 'actions' => ['remote_retire' => count($selected)],
                'writes' => false, 'provider_calls' => false, 'cutover_authorized' => false];
        }
        if (! $hash || ! hash_equals($packetHash, $hash) || ! Schema::hasTable('provider_obligation_reviews')) {
            throw new PaymentHistoryResetRefused('Exact remote review hash and migrated evidence schema are required.');
        }
        if (DB::transactionLevel() > 0 && ! app()->runningUnitTests()) {
            throw new PaymentHistoryResetRefused('Remote retirement cannot run inside another transaction.');
        }
        $prepared = app(AdminOperationRunner::class)->run($operation, 'admin.finance', self::PREPARE, null,
            ['review_hash' => $packetHash], $reason, function () use ($packet, $selected, $operation, $admin, $packetHash) {
                $this->authorize();
                $this->preEpoch();
                $snapshot = app(ProviderObligationBatchReview::class)->validate($packet, true);
                $ids = [];
                foreach ($selected as $decision) {
                    $payment = $snapshot->payment($decision['payment_id']);
                    if (! $payment || ! $snapshot->remoteEligible($payment)) {
                        throw new PaymentHistoryResetRefused('Remote identity is not eligible.');
                    }
                    $event = app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_retirement_intent',
                        'event_key' => 'provider-retirement:'.$operation.':'.$payment->id, 'source' => 'admin_provider_batch_review',
                        'before_status' => $payment->status->value, 'after_status' => $payment->status->value,
                        'meta' => ['operation_id' => $operation, 'review_hash' => $packetHash]]);
                    $ids[] = DB::table('provider_obligation_reviews')->insertGetId([
                        'original_payment_id' => $payment->id, 'customer_id' => $payment->customer_id, 'provider' => 'fib',
                        'provider_object_id' => $payment->fib_subscription_id, 'kind' => 'remote_retirement', 'outcome' => 'pending',
                        'provider_status' => null, 'evidence_event_id' => $event->id, 'basis_hash' => '',
                        'manifest_hash' => $packet['review']['manifest_hash'], 'admin_id' => $admin->id, 'operation_id' => $operation,
                        'evidence' => json_encode(['phase' => 'prepared', 'parent_operation' => $operation, 'post_started' => false], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $snapshot = ProviderReviewSnapshot::capture(true);
                foreach ($ids as $id) {
                    $row = $snapshot->rows['provider_obligation_reviews'][$id];
                    DB::table('provider_obligation_reviews')->where('id', $id)->update(['basis_hash' => $snapshot->basis($snapshot->payment($row['original_payment_id']))]);
                }

                return ['review_hash' => $packetHash, 'review_ids' => $ids];
            });
        $results = [];
        foreach ($prepared['review_ids'] as $index => $id) {
            $this->authorize();
            if ($index > 0) {
                $this->pause(max(500, min(10000, (int) config('provider_obligation_review.get_interval_ms', 1000))));
            }
            $results[] = $this->process($id, $operation, $reason);
        }

        return ['review_hash' => $packetHash, 'observations' => $results, 'cutover_authorized' => false];
    }

    private function selected(array $packet): array
    {
        $items = collect($packet['review']['items'] ?? [])->whereNotNull('payment_id')->keyBy('payment_id');
        $selected = [];
        foreach ($packet['decisions'] ?? [] as $d) {
            if (! is_bool($d['selected'] ?? null)) {
                throw new PaymentHistoryResetRefused('Explicit selection required.');
            }
            if (! $d['selected']) {
                continue;
            }
            $item = $items[$d['payment_id'] ?? 0] ?? null;
            if (($d['action'] ?? null) !== 'remote_retire' || ! $item || ! ($item['remote_review_eligible'] ?? false)
                || ! is_int($d['payment_id']) || isset($selected[$d['payment_id']])
                || ($d['customer_id'] ?? null) !== $item['customer_id'] || ($d['provider_subscription_id'] ?? null) !== $item['provider_object_id']
                || array_diff(array_keys($d), ['action', 'selected', 'payment_id', 'customer_id', 'provider_subscription_id'])) {
                throw new PaymentHistoryResetRefused('Exact remote-only selection required; do not mix coverage or merchant actions.');
            }
            $selected[$d['payment_id']] = $d;
        }
        if (! $selected || count($selected) > min(25, max(1, (int) config('provider_obligation_review.max_gets', 25)))) {
            throw new PaymentHistoryResetRefused('Select 1–25 provider objects.');
        }
        foreach (['retries' => [1, 3], 'timeout' => [1, 30], 'retry_sleep_ms' => [0, 10000]] as $key => [$min, $max]) {
            $value = config('fib.http.'.$key);
            if (! is_numeric($value) || $value < $min || $value > $max) {
                throw new PaymentHistoryResetRefused('Bounded FIB transport required.');
            }
        }
        ksort($selected);

        return array_values($selected);
    }

    private function process(int $id, string $parent, string $reason): array
    {
        $child = Uuid::uuid5($parent, 'provider-remote-result:'.$id)->toString();
        if ($done = AdminOperation::whereKey($child)->where('status', 'completed')->first()) {
            return $done->result['observation'];
        }
        $owner = (string) Str::uuid();
        $claim = DB::transaction(function () use ($id, $owner) {
            $this->authorize();
            $this->preEpoch();
            $row = (array) DB::table('provider_obligation_reviews')->where('id', $id)->lockForUpdate()->first();
            $evidence = json_decode($row['evidence'], true);
            if (($evidence['phase'] ?? null) === 'completed') {
                return ['completed' => true];
            }
            if (! empty($evidence['lease_until']) && FibSubscriptionTimestamp::parse($evidence['lease_until'])?->isFuture()) {
                throw new PaymentHistoryResetRefused('This object is in progress; retry its UUID after the bounded lease.');
            }
            $snapshot = ProviderReviewSnapshot::capture(true);
            $payment = $snapshot->payment($row['original_payment_id']);
            $valid = $payment && $snapshot->remoteEligible($payment) && hash_equals($row['basis_hash'], $snapshot->basis($payment));
            $evidence = array_replace($evidence, ['phase' => 'running', 'lease_owner' => $owner, 'lease_until' => now()->addMinutes(10)->toIso8601String()]);
            DB::table('provider_obligation_reviews')->where('id', $id)->update(['evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return ['row' => $row, 'payment' => $payment, 'valid' => $valid];
        });
        if ($claim['completed'] ?? false) {
            return AdminOperation::findOrFail($child)->result['observation'];
        }
        $result = ['result' => 'provider_error', 'provider_status' => null, 'last_payment_at' => null, 'active_until' => null];
        if ($claim['valid']) {
            try {
                $result = app(FibSubscriptionCancellationService::class)->cancel($claim['payment'], true,
                    fn ($status) => $this->fencePost($id, $owner, $status));
            } catch (\Throwable) {
                // The committed fence remains even if HTTP or the process fails ambiguously.
            }
            $evidence = json_decode(DB::table('provider_obligation_reviews')->where('id', $id)->value('evidence'), true);
            if ($evidence['post_started'] ?? false) {
                try {
                    $result = app(FibSubscriptionCancellationService::class)->cancel($claim['payment'], false);
                } catch (\Throwable) {
                    $result = ['result' => 'provider_error', 'provider_status' => null, 'last_payment_at' => null, 'active_until' => null];
                }
            }
        }

        return app(AdminOperationRunner::class)->run($child, 'admin.finance', self::RESULT, $claim['row']['customer_id'],
            ['parent_operation' => $parent, 'review_id' => $id], $reason, function () use ($id, $owner, $child, $claim, $result) {
                $this->authorize();
                $this->preEpoch();
                $row = (array) DB::table('provider_obligation_reviews')->where('id', $id)->lockForUpdate()->first();
                $evidence = json_decode($row['evidence'], true);
                if (($evidence['lease_owner'] ?? null) !== $owner) {
                    throw new PaymentHistoryResetRefused('Remote lease changed.');
                }
                $snapshot = ProviderReviewSnapshot::capture(true);
                $payment = $snapshot->payment($row['original_payment_id']);
                $unchanged = $payment && $snapshot->remoteEligible($payment) && hash_equals($row['basis_hash'], $snapshot->basis($payment));
                $verified = $claim['valid'] && $unchanged && $result['result'] !== 'provider_error';
                $status = $verified && in_array($result['provider_status'], ['ACTIVE', 'TRIAL', 'DRAFT', 'CANCELED', 'CANCELLED', 'REJECTED'], true) ? $result['provider_status'] : null;
                $start = $result['last_payment_at']?->format('Y-m-d\TH:i:s.vP');
                $end = $result['active_until']?->format('Y-m-d\TH:i:s.vP');
                // Preserve pre-POST paid evidence even if cancellation removes a response boundary.
                foreach (['last_payment_at' => &$start, 'active_until' => &$end] as $key => &$date) {
                    $before = $evidence['before_post'][$key] ?? null;
                    if ($before && (! $date || FibSubscriptionTimestamp::parse($before)->gt(FibSubscriptionTimestamp::parse($date)))) {
                        $date = $before;
                    }
                }
                unset($date);
                if (($start && FibSubscriptionTimestamp::parse($start)?->isFuture())
                    || ($start && $end && ! FibSubscriptionTimestamp::parse($end)?->gt(FibSubscriptionTimestamp::parse($start)))) {
                    $verified = false;
                }
                $outcome = 'unresolved';
                if ($verified && in_array($status, ['CANCELLED', 'CANCELED', 'REJECTED'], true)) {
                    $outcome = $snapshot->unpaidUnbound($payment) && ! $start && ! $end ? 'confirmed_retired' : 'renewal_stopped';
                }
                unset($evidence['lease_owner'], $evidence['lease_until']);
                $evidence = array_replace($evidence, ['phase' => 'completed', 'channel' => 'authenticated_get', 'verified' => $verified,
                    'reason' => ! $unchanged ? 'source_changed' : ($status === 'DRAFT' ? 'draft_not_contractually_cancellable' : ($verified ? 'verified_observation' : 'provider_unavailable')),
                    'last_payment_at' => $start, 'active_until' => $end]);
                if (! $payment) {
                    throw new PaymentHistoryResetRefused('Payment removed during remote review.');
                }
                $event = app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_retirement_observed',
                    'event_key' => 'provider-retirement-result:'.$child, 'source' => 'admin_provider_batch_review',
                    'before_status' => $payment->status->value, 'after_status' => $payment->status->value,
                    'payload' => ['outcome' => $outcome, 'provider_status' => $status], 'meta' => ['operation_id' => $child]]);
                $snapshot = ProviderReviewSnapshot::capture(true);
                $row = array_replace($row, ['operation_id' => $child, 'outcome' => $outcome, 'provider_status' => $status,
                    'evidence_event_id' => $event->id, 'basis_hash' => $snapshot->basis($snapshot->payment($payment->id)),
                    'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'updated_at' => now()->toDateTimeString()]);
                DB::table('provider_obligation_reviews')->where('id', $id)->update($row);
                $observation = ['payment_id' => $payment->id, 'customer_id' => $payment->customer_id, 'outcome' => $outcome,
                    'provider_status' => $status, 'post_started' => (bool) ($evidence['post_started'] ?? false)];

                return ['observation' => $observation, 'evidence_hashes' => [$id => ProviderRetirementEvidence::approvalHash($row)]];
            })['observation'];
    }

    private function fencePost(int $id, string $owner, $status): bool
    {
        return DB::transaction(function () use ($id, $owner, $status) {
            $this->authorize();
            $this->preEpoch();
            $snapshot = ProviderReviewSnapshot::capture(true);
            $row = $snapshot->rows['provider_obligation_reviews'][$id];
            $evidence = json_decode($row['evidence'], true);
            $payment = $snapshot->payment($row['original_payment_id']);
            if (($evidence['lease_owner'] ?? null) !== $owner || ! FibSubscriptionTimestamp::parse($evidence['lease_until'])?->isFuture()
                || ! $snapshot->remoteEligible($payment) || ! hash_equals($row['basis_hash'], $snapshot->basis($payment))
                || ($status->lastPaymentAt && $status->lastPaymentAt->isFuture())) {
                throw new PaymentHistoryResetRefused('Provider POST identity or source changed.');
            }
            foreach ($snapshot->rows['provider_obligation_reviews'] as $prior) {
                if ($prior['provider_object_id'] === $row['provider_object_id'] && data_get(json_decode($prior['evidence'], true), 'post_started')) {
                    return false;
                }
            }
            $evidence['post_started'] = true; // Commit BEFORE any cancel POST; never reset, even after failure.
            $evidence['post_started_at'] = now()->toIso8601String();
            $evidence['before_post'] = ['status' => $status->status, 'last_payment_at' => $status->lastPaymentAt?->format('Y-m-d\TH:i:s.vP'),
                'active_until' => $status->activeUntil?->format('Y-m-d\TH:i:s.vP')];
            DB::table('provider_obligation_reviews')->where('id', $id)->update(['evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return true;
        });
    }

    private function preEpoch(): void
    {
        if (app(BillingReportingBoundary::class)->current()) {
            throw new PaymentHistoryResetRefused('Remote retirement requires a pre-cutover database.');
        }
    }

    private function authorize(): \App\Models\User
    {
        $admin = AdminAccess::authorize('admin.finance');
        AdminAccess::authorize('admin.reconcile');

        return $admin;
    }

    protected function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}

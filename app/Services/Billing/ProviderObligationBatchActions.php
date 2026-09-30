<?php

namespace App\Services\Billing;

use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Support\FibStatusEvidence;
use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Services\Admin\AdminOperationRunner;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

class ProviderObligationBatchActions
{
    public const ACTION = 'billing.provider_obligations.batch';

    public function apply(array $packet, string $operation, string $reason, ?string $reviewHash = null, bool $execute = false): array
    {
        $this->authorize();
        Validator::make(compact('operation', 'reason'), ['operation' => 'required|uuid', 'reason' => 'required|string|min:10|max:1000'])->validate();
        if (collect($packet['decisions'] ?? [])->contains(fn ($d) => ($d['selected'] ?? false) && ($d['action'] ?? null) === 'remote_retire')) {
            return app(ProviderRemoteRetirementBatch::class)->apply($packet, $operation, $reason, $reviewHash, $execute);
        }
        $approvalHash = ProviderReviewSnapshot::hash($packet);
        if (! $execute) {
            $snapshot = app(ProviderObligationBatchReview::class)->validate($packet);
            $decisions = $this->decisions($packet, $snapshot, $operation, $reason);

            return ['review_hash' => $approvalHash, 'selected' => count($decisions), 'actions' => array_count_values(array_column($decisions, 'action')),
                'writes' => false, 'provider_calls' => false, 'cutover_authorized' => false];
        }
        if (! $reviewHash || ! hash_equals($approvalHash, $reviewHash)) {
            throw new PaymentHistoryResetRefused('The exact reviewed packet hash is required.');
        }
        if (! Schema::hasTable('provider_obligation_reviews')) {
            throw new PaymentHistoryResetRefused('Provider review schema is missing.');
        }

        return app(AdminOperationRunner::class)->run($operation, 'admin.finance', self::ACTION, null,
            ['review_hash' => $approvalHash, 'manifest_hash' => $packet['review']['manifest_hash']], $reason,
            function () use ($packet, $operation, $reason, $approvalHash) {
                $admin = $this->authorize();
                if (app(BillingReportingBoundary::class)->current()) {
                    throw new PaymentHistoryResetRefused('New provider actions require a pre-cutover database.');
                }
                $snapshot = app(ProviderObligationBatchReview::class)->validate($packet, true);
                $decisions = $this->decisions($packet, $snapshot, $operation, $reason);
                $result = ['review_hash' => $approvalHash, 'coverage' => [], 'observations' => [], 'evidence_hashes' => [], 'cutover_authorized' => false];
                $pending = [];
                $lastGet = null;
                foreach ($decisions as $decision) {
                    $this->authorize();
                    $payment = $snapshot->payment($decision['payment_id']);
                    if ($decision['action'] === 'coverage_approval') {
                        $result['coverage'][] = app(ProviderCoverageDispositions::class)->approve($this->coverageRequest($decision),
                            $this->childOperation($operation, $payment->id), $reason);

                        continue;
                    }
                    if ($decision['action'] === 'draft_get') {
                        if ($lastGet !== null) {
                            $interval = max(500, min(10000, (int) config('provider_obligation_review.get_interval_ms', 1000)));
                            $this->pause(max(0, $interval - (int) ((hrtime(true) - $lastGet) / 1000000)));
                        }
                        $lastGet = hrtime(true);
                        $evidence = ['channel' => 'authenticated_get', 'reason' => 'provider_unavailable'];
                        $statusName = null;
                        $outcome = 'unresolved';
                        try {
                            $status = app(FibSubscriptionService::class)->getStatus($payment);
                            $rejection = app(FibStatusEvidence::class)->rejection($payment, $status);
                            if ($rejection) {
                                $evidence['reason'] = $rejection;
                            } else {
                                $statusName = preg_match('/^[A-Z_]{1,30}$/D', $status->status) ? $status->status : null;
                                $evidence = ['channel' => 'authenticated_get', 'reason' => 'verified_observation',
                                    'active_until' => $status->activeUntil?->format('Y-m-d\TH:i:s.vP'),
                                    'last_payment_at' => $status->lastPaymentAt?->format('Y-m-d\TH:i:s.vP')];
                                if (in_array($statusName, ['CANCELED', 'CANCELLED', 'REJECTED'], true) && ! $status->activeUntil && ! $status->lastPaymentAt) {
                                    $outcome = 'confirmed_retired';
                                }
                            }
                        } catch (\Throwable) {
                            // No raw provider exception, authentication response or payload is persisted here.
                        }
                    } else {
                        $statusName = $decision['disposition'];
                        $outcome = 'confirmed_retired';
                        $evidence = array_intersect_key($decision, array_flip(['review_reference', 'evidence_sha256', 'observed_at',
                            'attested', 'no_collection', 'irreversibly_non_activatable', 'disposition']));
                        $evidence['channel'] = 'merchant_attestation';
                    }
                    $event = app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_obligation_reviewed',
                        'event_key' => 'provider-review:'.$operation.':'.$payment->id, 'source' => 'admin_provider_batch_review',
                        'before_status' => $payment->status?->value, 'after_status' => $payment->status?->value,
                        'payload' => ['outcome' => $outcome, 'provider_status' => $statusName],
                        'meta' => ['operation_id' => $operation, 'manifest_hash' => $packet['review']['manifest_hash'], 'channel' => $evidence['channel']]]);
                    $pending[] = ['original_payment_id' => $payment->id, 'customer_id' => $payment->customer_id, 'provider' => 'fib',
                        'provider_object_id' => $payment->fib_subscription_id, 'kind' => $decision['action'], 'outcome' => $outcome,
                        'provider_status' => $statusName, 'evidence_event_id' => $event->id, 'manifest_hash' => $packet['review']['manifest_hash'],
                        'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'admin_id' => $admin->id, 'operation_id' => $operation,
                        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
                    $result['observations'][] = ['payment_id' => $payment->id, 'outcome' => $outcome, 'provider_status' => $statusName];
                }
                $afterEvents = ProviderReviewSnapshot::capture(true);
                foreach ($pending as $row) {
                    $row['basis_hash'] = $afterEvents->basis($afterEvents->payment($row['original_payment_id']));
                    $id = DB::table('provider_obligation_reviews')->insertGetId($row);
                    $result['evidence_hashes'][$id] = ProviderRetirementEvidence::approvalHash($row);
                }
                $this->authorize();

                return $result;
            });
    }

    protected function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function authorize(): \App\Models\User
    {
        $admin = AdminAccess::authorize('admin.finance');
        AdminAccess::authorize('admin.reconcile');

        return $admin;
    }

    private function decisions(array $packet, ProviderReviewSnapshot $snapshot, string $operation, string $reason): array
    {
        $items = collect($packet['review']['items'])->whereNotNull('payment_id')->keyBy('payment_id');
        $selected = [];
        foreach ($packet['decisions'] as $decision) {
            if (! is_array($decision) || ! is_bool($decision['selected'] ?? null)) {
                throw new PaymentHistoryResetRefused('Each decision needs an explicit boolean selection.');
            }
            if (! $decision['selected']) {
                continue;
            }
            $id = $decision['payment_id'] ?? 0;
            $item = $items[$id] ?? null;
            if (! is_int($id) || ! $item || isset($selected[$id]) || ! in_array($decision['action'] ?? null, $item['proposed_actions'], true)
                || ($decision['customer_id'] ?? null) !== $item['customer_id'] || ($decision['provider_subscription_id'] ?? null) !== $item['provider_object_id']) {
                throw new PaymentHistoryResetRefused('Selection or exact identity does not match the manifest.');
            }
            $base = ['action', 'selected', 'payment_id', 'customer_id', 'provider_subscription_id'];
            if ($decision['action'] === 'coverage_approval') {
                $allowed = [...$base, 'subscription_kind', 'subscription_id', 'evidence_event_id', 'coverage_start', 'coverage_end', 'coverage_confirmed', 'review_reference'];
                if (($decision['subscription_kind'] ?? null) !== $item['subscriptions'][0]['kind']
                    || ($decision['subscription_id'] ?? null) !== $item['subscriptions'][0]['id']
                    || ($decision['evidence_event_id'] ?? null) !== $item['evidence_event_id']) {
                    throw new PaymentHistoryResetRefused('Coverage identity or event mismatch.');
                }
                app(ProviderCoverageDispositions::class)->review($this->coverageRequest($decision), $this->childOperation($operation, $id), $reason);
            } else {
                $allowed = $base;
                $payment = $snapshot->payment($id);
                if (! $snapshot->unpaidUnbound($payment) || $payment->provider_subscription_status !== 'DRAFT') {
                    throw new PaymentHistoryResetRefused('Only exact unpaid unbound DRAFT objects are eligible for this workflow.');
                }
                if ($decision['action'] === 'merchant_attestation') {
                    $allowed = [...$base, 'review_reference', 'evidence_sha256', 'observed_at', 'attested', 'no_collection', 'irreversibly_non_activatable', 'disposition'];
                    Validator::make($decision, ['review_reference' => 'required|string|min:5|max:200',
                        'evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'attested' => 'accepted', 'no_collection' => 'accepted',
                        'irreversibly_non_activatable' => 'accepted', 'disposition' => 'required|in:CANCELLED,REJECTED,PERMANENTLY_NON_ACTIVATABLE'])->validate();
                    $observed = FibSubscriptionTimestamp::parse($decision['observed_at'] ?? null);
                    if (! $observed || $observed->isFuture() || $observed->lt(FibSubscriptionTimestamp::parse($packet['review']['reviewed_at']))) {
                        throw new PaymentHistoryResetRefused('Merchant evidence must be reviewed after this inventory and cannot be future-dated.');
                    }
                }
            }
            if (array_diff(array_keys($decision), $allowed)) {
                throw new PaymentHistoryResetRefused('Unexpected decision fields; raw provider payloads are not accepted.');
            }
            $selected[$id] = $decision;
        }
        if (! $selected || count($selected) > max(1, min(100, (int) config('provider_obligation_review.max_actions', 100)))
            || count(array_filter($selected, fn ($d) => $d['action'] === 'draft_get')) > max(1, min(25, (int) config('provider_obligation_review.max_gets', 25)))) {
            throw new PaymentHistoryResetRefused('Select a nonempty bounded batch; at most 100 actions and 25 sequential GETs.');
        }
        if (in_array('draft_get', array_column($selected, 'action'), true)) {
            // The shared authenticated client includes retries and token exchange. Refuse an
            // unbounded transport configuration rather than changing ordinary payment behavior.
            foreach (['retries' => [1, 3], 'timeout' => [1, 30], 'retry_sleep_ms' => [0, 10000]] as $key => [$min, $max]) {
                $value = config('fib.http.'.$key);
                if (! is_numeric($value) || $value < $min || $value > $max) {
                    throw new PaymentHistoryResetRefused('Batch GET requires bounded FIB HTTP retries, timeout and retry delay.');
                }
            }
        }
        ksort($selected);

        return array_values($selected);
    }

    private function coverageRequest(array $decision): array
    {
        unset($decision['action'], $decision['selected']);

        return $decision;
    }

    private function childOperation(string $operation, int $paymentId): string
    {
        return Uuid::uuid5($operation, 'provider-coverage:'.$paymentId)->toString();
    }
}

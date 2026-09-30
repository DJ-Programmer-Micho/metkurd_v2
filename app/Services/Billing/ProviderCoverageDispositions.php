<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ProviderCoverageDisposition;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Admin\AdminOperationRunner;
use App\Support\Admin\AdminAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** No provider calls or financial fulfillment. Approval becomes authority only at audited cutover. */
class ProviderCoverageDispositions
{
    public const ACTION = 'billing.provider_coverage.approve';

    public const PROVENANCE = 'legacy_provider_paid_coverage';

    public function review(array $request, string $operation, string $reason): array
    {
        $this->authorize();
        $this->validate($request, $operation, $reason);
        $facts = $this->facts($request);

        return ['payment_id' => $request['payment_id'], 'subscription_kind' => $request['subscription_kind'],
            'subscription_id' => $request['subscription_id'], 'coverage_start' => $facts['coverage_start'],
            'coverage_end' => $facts['coverage_end'], 'evidence_event_id' => $request['evidence_event_id'],
            'cutover_authorized' => false, 'writes' => false];
    }

    public function approve(array $request, string $operation, string $reason): array
    {
        $this->authorize();
        $this->validate($request, $operation, $reason);

        return app(AdminOperationRunner::class)->run($operation, 'admin.finance', self::ACTION,
            $request['customer_id'], $request, $reason, function () use ($request, $operation, $reason) {
                $admin = $this->authorize();
                $facts = $this->facts($request, true);
                if (ProviderCoverageDisposition::where('original_payment_id', $request['payment_id'])->exists()) {
                    throw new PaymentHistoryResetRefused('A disposition already exists; replay its original operation.');
                }
                $row = ProviderCoverageDisposition::create($facts + [
                    'customer_id' => $request['customer_id'], 'original_payment_id' => $request['payment_id'],
                    'provider' => 'fib', 'provider_subscription_id' => $request['provider_subscription_id'],
                    'subscription_kind' => $request['subscription_kind'], 'subscription_id' => $request['subscription_id'],
                    'renewal_stop_confirmed' => true, 'evidence_event_id' => $request['evidence_event_id'],
                    'status' => 'approved', 'provenance' => self::PROVENANCE, 'admin_id' => $admin->id,
                    'operation_id' => $operation, 'reason' => $reason, 'review_reference' => $request['review_reference'],
                ]);

                return ['disposition_id' => $row->id, 'approval_hash' => $this->approvalHash($row),
                    'coverage_start' => $row->coverage_start, 'coverage_end' => $row->coverage_end];
            });
    }

    private function authorize(): \App\Models\User
    {
        $admin = AdminAccess::authorize('admin.finance');
        AdminAccess::authorize('admin.reconcile');

        return $admin;
    }

    private function validate(array $request, string $operation, string $reason): void
    {
        Validator::make($request + compact('operation', 'reason'), [
            'customer_id' => 'required|integer|min:1', 'payment_id' => 'required|integer|min:1',
            'provider_subscription_id' => 'required|string|max:255', 'subscription_kind' => 'required|in:service,storage',
            'subscription_id' => 'required|integer|min:1', 'evidence_event_id' => 'required|integer|min:1',
            'coverage_start' => 'required|string|max:40', 'coverage_end' => 'required|string|max:40',
            'coverage_confirmed' => 'accepted', 'review_reference' => 'required|string|min:5|max:200',
            'operation' => 'required|uuid', 'reason' => 'required|string|min:10|max:1000',
        ])->validate();
        if (! Schema::hasTable('provider_coverage_dispositions')) {
            throw new PaymentHistoryResetRefused('Disposition requires migrated schema and a pre-cutover database.');
        }
    }

    private function facts(array $request, bool $lock = false): array
    {
        if (app(BillingReportingBoundary::class)->current()) {
            throw new PaymentHistoryResetRefused('New dispositions require a pre-cutover database.');
        }
        $payment = Payment::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($request['payment_id']);
        $service = $request['subscription_kind'] === 'service';
        $class = $service ? CustomerServiceSubscription::class : CustomerStorageSubscription::class;
        $subscription = $class::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($request['subscription_id']);
        $event = DB::table('payment_events')->when($lock, fn ($q) => $q->lockForUpdate())->find($request['evidence_event_id']);
        $confirmation = app(ProviderSubscriptionCancellation::class)->confirmation($payment);
        $start = FibSubscriptionTimestamp::parse($request['coverage_start']);
        $end = FibSubscriptionTimestamp::parse($request['coverage_end']);
        $observedStart = FibSubscriptionTimestamp::parse($confirmation['observed_last_payment_at'] ?? null);
        $observedEnd = FibSubscriptionTimestamp::parse($confirmation['observed_active_until'] ?? null);
        $planId = $subscription->{$request['subscription_kind'].'_plan_id'};
        if ($payment->provider?->value !== 'fib' || ! $payment->isProviderSubscriptionObject()
            || $payment->status?->value !== 'paid' || ! $payment->isFulfilled() || ! $payment->paid_at
            || $payment->review_required_at || $payment->requiresReview() || data_get($payment->meta, 'provider_evidence_rejection')
            || (int) $payment->customer_id !== (int) $request['customer_id']
            || (int) $subscription->customer_id !== (int) $request['customer_id']
            || (int) $subscription->payment_id !== (int) $payment->id || $subscription->source !== 'fib'
            || $subscription->status !== 'active' || data_get($subscription->meta, 'superseded_at')
            || $subscription->provider_ref !== $request['provider_subscription_id']
            || $payment->fib_subscription_id !== $request['provider_subscription_id']
            || $payment->purchasable_type !== ($service ? ServicePlan::class : StoragePlan::class)
            || (int) $payment->purchasable_id !== (int) $planId
            || ! ($confirmation['confirmed'] ?? false) || ! $event
            || (int) ($confirmation['evidence_event_id'] ?? 0) !== (int) $event->id
            || ! $start || ! $end || ! $observedStart || ! $observedEnd || ! $end->gt($start)
            || $start->isFuture() || ! $end->isFuture() || ! $start->eq($observedStart) || ! $end->eq($observedEnd)
            || $subscription->newQuery()->where('customer_id', $subscription->customer_id)->where('id', '>', $subscription->id)->exists()) {
            throw new PaymentHistoryResetRefused('Coverage approval refused: identity, paid term or authenticated cancellation evidence does not match.');
        }
        // Conflicting/malformed independent terms cannot be hidden by choosing one observation.
        foreach ([$payment->active_until, data_get($payment->meta, 'verified_subscription_collection.paid_through'),
            data_get($payment->meta, 'provider_cancellation.retained_active_until'), $subscription->ends_at,
            data_get($subscription->meta, 'period_ends_at'), data_get($subscription->meta, 'provider_active_until')] as $raw) {
            if ($raw !== null && (! ($known = FibSubscriptionTimestamp::parse($raw instanceof \DateTimeInterface ? $raw->format(DATE_ATOM) : $raw)) || $known->gt($end))) {
                throw new PaymentHistoryResetRefused('An independent coverage boundary requires further review.');
            }
        }

        return ['plan_id' => $planId, 'coverage_start' => $start->copy()->utc()->format('Y-m-d\TH:i:s.vP'),
            'coverage_end' => $end->copy()->utc()->format('Y-m-d\TH:i:s.vP'),
            'source_hash' => $this->hash([$payment->getRawOriginal(), $subscription->getRawOriginal(), (array) $event]),
            'snapshot' => ['payment' => $payment->only(['id', 'uuid', 'customer_id', 'provider', 'amount', 'currency', 'status', 'internal_status', 'paid_at', 'fulfilled_at', 'purchasable_type', 'purchasable_id']),
                'subscription' => $subscription->only(['id', 'customer_id', 'source', 'provider_ref', 'starts_at', 'ends_at', 'status', 'auto_renew']),
                'cancellation_evidence' => $confirmation]];
    }

    /** Revalidate the immutable approval against live persisted evidence before retiring any source row. */
    public function approvedFor(Payment $payment): ?ProviderCoverageDisposition
    {
        if (! Schema::hasTable('provider_coverage_dispositions')) {
            return null;
        }
        $row = ProviderCoverageDisposition::with('operation')->where('original_payment_id', $payment->id)->where('status', 'approved')->first();
        if (! $row || ! $this->audited($row)) {
            return null;
        }
        try {
            $facts = $this->facts(['payment_id' => $row->original_payment_id, 'customer_id' => $row->customer_id,
                'subscription_kind' => $row->subscription_kind, 'subscription_id' => $row->subscription_id,
                'provider_subscription_id' => $row->provider_subscription_id, 'evidence_event_id' => $row->evidence_event_id,
                'coverage_start' => $row->coverage_start, 'coverage_end' => $row->coverage_end]);

            return hash_equals($row->source_hash, $facts['source_hash']) ? $row : null;
        } catch (\Throwable) {
            return null; // Stale approvals never become a cutover override.
        }
    }

    public function retainedFor(CustomerServiceSubscription|CustomerStorageSubscription $subscription): ?ProviderCoverageDisposition
    {
        if (! Schema::hasTable('provider_coverage_dispositions')) {
            return null;
        }
        $row = ProviderCoverageDisposition::with('operation')->where('subscription_kind', $subscription instanceof CustomerServiceSubscription ? 'service' : 'storage')
            ->where('subscription_id', $subscription->id)->where('status', 'retained')->first();
        $boundary = app(BillingReportingBoundary::class)->current();

        return $this->bound($row, $subscription, $boundary) ? $row : null;
    }

    private function bound(?ProviderCoverageDisposition $row, CustomerServiceSubscription|CustomerStorageSubscription $subscription, ?array $boundary): bool
    {
        if (! $row || ! $boundary || ! in_array((int) $row->id, array_map('intval', $boundary['provider_coverage_ids'] ?? []), true) || ! $this->audited($row) || $row->original_payment_id > $boundary['payment_id']
            || $subscription->payment_id !== null || $subscription->source !== $row->provider || $subscription->auto_renew
            || (int) $row->customer_id !== (int) $subscription->customer_id
            || (int) $row->plan_id !== (int) $subscription->{$row->subscription_kind.'_plan_id'}
            || $row->provider_subscription_id !== $subscription->provider_ref) {
            return false;
        }

        return true;
    }

    public function eligibleIds(string $model, CarbonInterface $at): array
    {
        if (! Schema::hasTable('provider_coverage_dispositions')) {
            return [];
        }
        $kind = $model === CustomerServiceSubscription::class ? 'service' : 'storage';
        $rows = ProviderCoverageDisposition::with('operation')->where('subscription_kind', $kind)->where('status', 'retained')->get()->keyBy('subscription_id');
        $boundary = app(BillingReportingBoundary::class)->current();
        $table = (new $model)->getTable();

        return $model::whereIn('id', $rows->keys())->whereNotExists(function ($q) use ($table) {
            $q->selectRaw('1')->from($table.' as newer')->whereColumn('newer.customer_id', $table.'.customer_id')
                ->whereColumn('newer.id', '>', $table.'.id');
        })->get()->filter(function ($subscription) use ($at, $rows, $boundary) {
            $row = $rows[$subscription->id];

            return $this->bound($row, $subscription, $boundary) && FibSubscriptionTimestamp::parse($row->coverage_start)?->lte($at)
                && FibSubscriptionTimestamp::parse($row->coverage_end)?->gt($at);
        })->pluck('id')->all();
    }

    private function audited(ProviderCoverageDisposition $row): bool
    {
        $operation = $row->operation;

        return $row->provider === 'fib' && $row->provenance === self::PROVENANCE && $row->renewal_stop_confirmed
            && $operation && $operation->status === 'completed' && $operation->action === self::ACTION
            && (int) $operation->customer_id === (int) $row->customer_id && (int) $operation->admin_id === (int) $row->admin_id
            && (int) data_get($operation->result, 'disposition_id') === (int) $row->id
            && hash_equals((string) data_get($operation->result, 'approval_hash', ''), $this->approvalHash($row));
    }

    private function approvalHash(ProviderCoverageDisposition $row): string
    {
        return $this->hash($row->only(['customer_id', 'original_payment_id', 'provider', 'provider_subscription_id',
            'subscription_kind', 'subscription_id', 'plan_id', 'coverage_start', 'coverage_end', 'renewal_stop_confirmed',
            'evidence_event_id', 'provenance', 'admin_id', 'operation_id', 'reason', 'review_reference', 'source_hash', 'snapshot']));
    }

    private function hash(array $data): string
    {
        // Native MySQL JSON canonicalizes object-key order; approval identity must not depend on it.
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonical($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));
    }
}

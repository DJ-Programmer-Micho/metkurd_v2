<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\AdminProviderEvidence;
use App\Support\Admin\AdminAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Maintenance-only, all-or-nothing reset. This is not a checkout/reconciliation policy. */
class PaymentHistoryReset
{
    public const PROCESSING = ['payment_events', 'payment_webhook_events', 'payment_transactions', 'payments', 'payment_intents'];

    private const DETACHABLE = ['credit_orders', 'customer_service_subscriptions', 'customer_storage_subscriptions'];

    private const TERMINAL_PROVIDER = ['CANCELED', 'CANCELLED', 'EXPIRED', 'REJECTED', 'FAILED', 'DECLINED', 'TIMED_OUT'];

    public function review(): array
    {
        $this->connectionIdentity();

        return DB::transaction(fn () => $this->inspect());
    }

    public function execute(string $hash, string $reason, bool $workersStopped = false): array
    {
        $this->connectionIdentity();
        $this->authorize($workersStopped);
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)
            || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000) {
            throw new PaymentHistoryResetRefused('Refused: valid review hash and substantive reason are required.');
        }

        return DB::transaction(function () use ($hash, $reason, $workersStopped) {
            // Same customer-before-Payment order as checkout/sync. Maintenance and stopped
            // workers/callbacks are essential: row locks alone do not fence new writers/DDL.
            $tables = Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false);
            sort($tables);
            foreach (array_unique(['customers', 'payments', ...$tables]) as $table) {
                foreach (DB::table($table)->lockForUpdate()->cursor() as $ignored) {
                }
            }
            $this->authorize($workersStopped);
            $review = $this->inspect();
            if ($review['blockers'] || ! hash_equals($review['review_hash'], $hash)) {
                throw new PaymentHistoryResetRefused('Refused: dependencies block reset or the reviewed database changed. Run dry-run again.');
            }
            $keepUuid = $review['keep']['uuid'];
            if (! array_sum($review['delete_counts'])) {
                throw new PaymentHistoryResetRefused('Nothing to reset; the selected Payment is already the only processing history.');
            }
            $this->authorize($workersStopped);
            $audit = app(AdminAudit::class);
            $previous = [$audit->reason, $audit->operationId];
            try {
                $audit->reason = $reason;
                $audit->operationId = null;
                // Persist the reviewed mappings before detachment, in the same transaction.
                // This audit lives outside PaymentEvents and cannot be erased by this reset.
                $audit->record('billing.reset_payment_history', self::class, $hash, [],
                    ['keep_payment' => $keepUuid, 'review_hash' => $hash], $review);
                $newAuditIds = DB::table('admin_audit_events')->where('action', 'billing.reset_payment_history')
                    ->where('target_type', self::class)->where('target_id', $hash)->pluck('id')->all();
                if (count($newAuditIds) !== 1 || $this->fingerprint('admin_audit_events', $newAuditIds) !== $review['fingerprints']['admin_audit_events']) {
                    throw new PaymentHistoryResetRefused('Existing Admin audit changed; reset rolled back.');
                }
                $auditHash = $this->fingerprint('admin_audit_events');
                $this->authorize($workersStopped);
                foreach ($review['detach_payment_links'] as $table => $links) {
                    foreach ($links as $link) {
                        if (DB::table($table)->where('id', $link['id'])->where('payment_id', $link['payment_id'])->update(['payment_id' => null]) !== 1) {
                            throw new PaymentHistoryResetRefused('Historical link changed during reset.');
                        }
                    }
                }
                foreach (self::PROCESSING as $table) {
                    // Transactions have a self-parent FK: explicit leaves before parents.
                    $ids = $table === 'payment_transactions' ? $review['transaction_delete_order'] : $review['delete_ids'][$table];
                    foreach (array_chunk($ids, $table === 'payment_transactions' ? 1 : 500) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->delete();
                    }
                }
                foreach ($review['expected_after'] as $table => $expected) {
                    if ($this->fingerprint($table) !== ($table === 'admin_audit_events' ? $auditHash : $expected)) {
                        throw new PaymentHistoryResetRefused('Preservation check failed; reset rolled back.');
                    }
                }
                $this->authorize($workersStopped);
            } finally {
                [$audit->reason, $audit->operationId] = $previous;
            }

            return ['keep_payment' => $keepUuid, 'review_hash' => $hash, 'deleted' => $review['delete_counts']];
        });
    }

    private function authorize(bool $workersStopped): void
    {
        AdminAccess::authorize('admin.finance');
        AdminAccess::authorize('admin.reconcile');
        if (! $workersStopped || ! app()->isDownForMaintenance()) {
            throw new PaymentHistoryResetRefused('Refused: maintenance mode and stopped workers/scheduler/callback intake attestation are required.');
        }
    }

    private function connectionIdentity(): array
    {
        $db = DB::connection();
        $driver = $db->getDriverName();
        if (($driver !== 'mysql' && ! (app()->environment('testing') && $driver === 'sqlite' && $db->getDatabaseName() === ':memory:'))
            || $db->getConfig('read') || $db->getConfig('write') || $db->getConfig('prefix') || $db->getConfig('unix_socket')) {
            throw new PaymentHistoryResetRefused('Unsupported database connection; use a single MySQL connection without replicas/prefix/socket, or isolated SQLite tests.');
        }

        $schema = $driver === 'mysql' ? DB::selectOne('SELECT DATABASE() AS name')->name : $db->getDatabaseName();
        if ($schema !== $db->getDatabaseName()) {
            throw new PaymentHistoryResetRefused('The active schema does not match the configured database.');
        }

        return ['environment' => app()->environment(), 'driver' => $driver, 'host' => $db->getConfig('host'),
            'port' => $db->getConfig('port'), 'schema' => $schema,
            'server_version' => DB::selectOne($driver === 'mysql' ? 'SELECT VERSION() AS version' : 'SELECT sqlite_version() AS version')->version,
            'timezone' => config('app.timezone')];
    }

    private function inspect(): array
    {
        $identity = $this->connectionIdentity();
        $tables = Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false);
        sort($tables);
        foreach ([...self::PROCESSING, ...self::DETACHABLE, 'subscription_credit_allocations', 'admin_audit_events'] as $required) {
            if (! in_array($required, $tables, true)) {
                throw new PaymentHistoryResetRefused('Required billing/Admin schema is missing; no reset is available.');
            }
        }
        $keep = $this->latestActivePayment();
        if (! $keep) {
            throw new PaymentHistoryResetRefused('No active Payment exists to retain. No reset is available; nothing was deleted.');
        }
        $delete = [];
        foreach (self::PROCESSING as $table) {
            $query = DB::table($table)->orderBy('id');
            if ($table === 'payments') {
                $query->where('id', '<>', $keep->id);
            } elseif ($table === 'payment_events') {
                $query->where(fn ($q) => $q->whereNull('payment_id')->orWhere('payment_id', '<>', $keep->id));
            }
            $delete[$table] = $query->pluck('id')->all();
        }
        $blockers = [];
        $archive = [];
        $detach = array_fill_keys(self::DETACHABLE, []);
        foreach (Payment::whereKeyNot($keep->id)->orderBy('id')->cursor() as $payment) {
            $reasons = $this->paymentBlockers($payment);
            if (($payment->fib_subscription_id && $payment->fib_subscription_id === $keep->fib_subscription_id)
                || ($payment->fib_payment_id && $payment->fib_payment_id === $keep->fib_payment_id)) {
                $reasons[] = 'provider_reference_shared_with_kept_payment';
            }
            foreach (['customer_service_subscriptions', 'customer_storage_subscriptions'] as $table) {
                $query = DB::table($table)->where('payment_id', $payment->id)
                    ->orWhere('meta->payment_id', $payment->id)->orWhere('meta->payment_id', (string) $payment->id);
                if ($payment->fib_subscription_id) {
                    $query->orWhere('provider_ref', $payment->fib_subscription_id)
                        ->orWhere('meta->provider_ref', $payment->fib_subscription_id)
                        ->orWhere('meta->fib_subscription_id', $payment->fib_subscription_id);
                }
                foreach ($query->orderBy('id')->get() as $subscription) {
                    if (! $this->historicalSubscription($subscription) || (int) $subscription->payment_id === (int) $keep->id) {
                        $reasons[] = $table.':'.$subscription->id.':current_or_unresolved_obligation';
                    } elseif ((int) $subscription->payment_id === (int) $payment->id) {
                        $detach[$table][$subscription->id] = ['id' => $subscription->id, 'payment_id' => $payment->id, 'payment_uuid' => $payment->uuid];
                    }
                }
            }
            if (DB::table('subscription_credit_allocations')->where('payment_id', $payment->id)->exists()) {
                $reasons[] = 'allocation_replay_evidence';
            }
            foreach (DB::table('credit_orders')->where('payment_id', $payment->id)->orderBy('id')->get() as $order) {
                $detach['credit_orders'][$order->id] = ['id' => $order->id, 'payment_id' => $payment->id, 'payment_uuid' => $payment->uuid];
            }
            $archive[] = array_merge($this->paymentSummary($payment), ['id' => $payment->id,
                'provider_subscription_reference' => $payment->fib_subscription_id, 'provider_payment_reference' => $payment->fib_payment_id]);
            foreach (array_unique($reasons) as $reason) {
                $blockers[] = ['table' => 'payments', 'id' => $payment->id, 'reason' => $reason];
            }
        }
        foreach (DB::table('payment_intents')->orderBy('id')->get() as $intent) {
            if (! in_array($intent->status, ['paid', 'refunded', 'failed', 'canceled', 'expired'], true)
                || ($intent->status === 'paid' && ! $intent->fulfilled_at) || $intent->provider_schedule_ref
                || ($intent->authorized_at && ! in_array($intent->status, ['paid', 'refunded'], true))) {
                $blockers[] = ['table' => 'payment_intents', 'id' => $intent->id, 'reason' => 'unresolved_legacy_payment_or_provider_schedule'];
            }
            foreach (['customer_service_subscriptions', 'customer_storage_subscriptions', 'credit_orders', 'payments'] as $dependent) {
                $references = DB::table($dependent)->where(fn ($q) => $q->where('meta->payment_intent_id', $intent->id)->orWhere('meta->payment_intent_id', (string) $intent->id));
                if ($dependent === 'payments') {
                    $references->where('id', $keep->id);
                }
                if ($references->exists()) {
                    $blockers[] = ['table' => $dependent, 'reason' => 'retained_legacy_intent_metadata:'.$intent->id];
                }
            }
        }
        foreach (DB::table('payment_transactions')->orderBy('id')->get() as $transaction) {
            if (! in_array($transaction->status, ['paid', 'succeeded', 'completed', 'refunded', 'failed', 'canceled', 'expired'], true)) {
                $blockers[] = ['table' => 'payment_transactions', 'id' => $transaction->id, 'reason' => 'unresolved_legacy_transaction'];
            }
        }
        foreach (DB::table('payment_webhook_events')->orderBy('id')->get() as $event) {
            if (! in_array($event->processing_status, ['processed', 'ignored'], true)) {
                $blockers[] = ['table' => 'payment_webhook_events', 'id' => $event->id, 'reason' => 'unprocessed_legacy_webhook'];
            }
        }
        $schema = [];
        $counts = $fingerprints = $expected = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumns($table);
            $keys = Schema::getForeignKeys($table);
            // Protect compatibility references even without a declared FK.
            foreach (['payment_id' => 'payments', 'payment_intent_id' => 'payment_intents'] as $column => $parent) {
                if (in_array($column, array_column($columns, 'name'), true)
                    && ! collect($keys)->contains(fn ($key) => $key['columns'] === [$column] && $key['foreign_table'] === $parent)) {
                    $keys[] = ['columns' => [$column], 'foreign_columns' => ['id'], 'foreign_table' => $parent, 'on_delete' => 'logical'];
                }
            }
            $schema[$table] = ['columns' => $columns, 'keys' => $keys, 'indexes' => Schema::getIndexes($table)];
            // Polymorphic ledger/provenance links have no physical foreign key.
            foreach (['source', 'related', 'reference'] as $prefix) {
                if (! in_array($prefix.'_type', array_column($columns, 'name'), true) || ! in_array($prefix.'_id', array_column($columns, 'name'), true)) {
                    continue;
                }
                foreach (['payments' => Payment::class, 'payment_intents' => \App\Models\PaymentIntent::class] as $parent => $class) {
                    $types = array_unique([$class, $parent, rtrim($parent, 's'), (new $class)->getMorphClass()]);
                    foreach (DB::table($table)->whereIn($prefix.'_type', $types)->whereIn($prefix.'_id', $delete[$parent])->get() as $row) {
                        if (! in_array($row->id ?? null, $delete[$table] ?? [], true)) {
                            $blockers[] = ['table' => $table, 'id' => $row->id ?? null, 'reason' => 'retained_polymorphic_dependency:'.$prefix.'->'.$parent];
                        }
                    }
                }
            }
            foreach ($keys as $key) {
                $parent = $key['foreign_table'];
                if ($parent === 'payments' && $key['foreign_columns'] === ['id'] && count($key['columns']) === 1
                    && in_array($table, ['payment_intents', 'payment_transactions', 'payment_webhook_events'], true)
                    && DB::table($table)->where($key['columns'][0], $keep->id)->exists()) {
                    $blockers[] = ['table' => $table, 'reason' => 'legacy_row_required_by_kept_payment'];
                }
                if (! isset($delete[$parent]) || ! $delete[$parent]) {
                    continue;
                }
                if ($key['foreign_columns'] !== ['id'] || count($key['columns']) !== 1) {
                    $blockers[] = ['table' => $table, 'reason' => 'unsupported_composite_dependency:'.$parent];

                    continue;
                }
                $column = $key['columns'][0];
                $allowed = $parent === 'payments' && $column === 'payment_id' ? array_keys($detach[$table] ?? []) : [];
                if ($allowed && ! collect($columns)->firstWhere('name', $column)['nullable']) {
                    $blockers[] = ['table' => $table, 'reason' => 'approved_link_is_not_nullable'];
                }
                // Query by parent selection rather than thousands of bound event IDs.
                $parentQuery = DB::table($parent)->select('id');
                if ($parent === 'payments') {
                    $parentQuery->where('id', '<>', $keep->id);
                }
                if ($parent === 'payment_events') {
                    $parentQuery->where(fn ($q) => $q->whereNull('payment_id')->orWhere('payment_id', '<>', $keep->id));
                }
                foreach (DB::table($table)->whereIn($column, $parentQuery)->get() as $child) {
                    if (! in_array($child->id ?? null, $delete[$table] ?? [], true) && ! in_array($child->id ?? null, $allowed, true)) {
                        $blockers[] = ['table' => $table, 'id' => $child->id ?? null, 'reason' => 'retained_dependency:'.$column.'->'.$parent];
                    }
                }
            }
            $counts[$table] = DB::table($table)->count();
            $fingerprints[$table] = $this->fingerprint($table);
            $expected[$table] = $this->fingerprint($table, $delete[$table] ?? [], array_keys($detach[$table] ?? []));
        }
        if ($identity['driver'] === 'mysql') {
            foreach (Schema::getTables(Schema::getCurrentSchemaName()) as $table) {
                if (strtolower($table['engine'] ?? '') !== 'innodb') {
                    $blockers[] = ['table' => $table['name'], 'reason' => 'non_transactional_engine'];
                }
            }
            if (DB::select('SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND TABLE_SCHEMA<>DATABASE() LIMIT 1')) {
                $blockers[] = ['reason' => 'cross_schema_foreign_key_requires_separate_review'];
            }
        }
        $order = $this->transactionOrder();
        if ($order === null) {
            $blockers[] = ['reason' => 'cyclic_legacy_transaction_parents'];
        }
        foreach ($detach as &$links) {
            ksort($links);
            $links = array_values($links);
        }
        unset($links);
        $schema['triggers'] = $identity['driver'] === 'mysql'
            ? DB::select('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')
            : DB::select("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='trigger' ORDER BY name");
        $result = ['policy' => 'payment-history-reset-v2', 'database' => $identity,
            'selection' => 'latest_active_by_created_at_then_id', 'keep' => $this->paymentSummary($keep),
            'counts' => $counts, 'delete_counts' => array_map('count', $delete), 'delete_ids' => $delete,
            'detach_payment_links' => $detach, 'archived_payments' => $archive,
            'transaction_delete_order' => $order ?? [], 'schema_hash' => $this->hash($schema),
            'fingerprints' => $fingerprints, 'expected_after' => $expected, 'blockers' => $blockers];
        $result['review_hash'] = $this->hash($result);

        return $result;
    }

    private function latestActivePayment(): ?Payment
    {
        $closed = ['failed', 'canceled', 'expired', 'refunded'];
        foreach (Payment::whereNotIn('status', $closed)
            ->where(fn ($query) => $query->whereNull('internal_status')->orWhereNotIn('internal_status', $closed))
            ->orderByDesc('created_at')->orderByDesc('id')->cursor() as $payment) {
            if (in_array(app(PaymentCheckoutState::class)->state($payment), ['awaiting', 'confirming', 'review', 'completed'], true)) {
                return $payment;
            }
        }

        return null;
    }

    private function paymentSummary(Payment $payment): array
    {
        return ['uuid' => $payment->uuid, 'customer_id' => $payment->customer_id, 'purchase_type' => $payment->purchase_type?->value,
            'created_at' => $payment->getRawOriginal('created_at'), 'status' => $payment->status?->value,
            'internal_status' => $payment->internal_status?->value, 'fulfilled' => $payment->isApplied(),
            'provider' => $payment->provider?->value, 'provider_type' => $payment->provider_object_type?->value,
            'amount' => $payment->amount, 'currency' => $payment->currency];
    }

    private function paymentBlockers(Payment $payment): array
    {
        $reasons = [];
        if ($payment->review_required_at || in_array($payment->internal_status?->value, ['requires_review', 'refund_requested', 'paid_pending_application'], true)
            || $payment->status?->value === 'refund_requested' || data_get($payment->meta, 'latest_sync_failure_pause_reconciliation')) {
            $reasons[] = 'unresolved_financial_review';
        }
        if ($this->futureOrInvalid($payment->getRawOriginal('active_until'))) {
            $reasons[] = 'future_or_unknown_paid_through';
        }
        if (in_array(strtoupper((string) $payment->provider_status), ['ACTIVE', 'SUBSCRIBED'], true)
            || in_array(strtoupper((string) $payment->provider_subscription_status), ['ACTIVE', 'SUBSCRIBED'], true)) {
            $reasons[] = 'current_provider_obligation';
        }
        if (($payment->fib_subscription_id || $payment->isProviderSubscriptionObject())
            && ! in_array(strtoupper((string) ($payment->provider_subscription_status ?: $payment->providerStatusLabel())), self::TERMINAL_PROVIDER, true)) {
            $reasons[] = 'provider_subscription_not_proven_retired';
        }
        foreach ([$payment->meta, $payment->status_response, $payment->create_response, $payment->callback_payload, $payment->cancel_response] as $payload) {
            if ($this->futureObligation((array) $payload)) {
                $reasons[] = 'retained_paid_through_or_cancellation_boundary';
            }
        }
        foreach ($payment->events()->lazyById(100) as $event) {
            if ($this->futureObligation((array) $event->payload) || $this->futureObligation((array) $event->meta)) {
                $reasons[] = 'event_paid_through_or_cancellation_boundary';
                break;
            }
        }
        if (! $payment->isApplied() && (app(AdminProviderEvidence::class)->preventsCheckoutInvalidation($payment)
            || $payment->paid_at || $payment->last_payment_at || $payment->active_until || $payment->status?->value === 'paid')) {
            $reasons[] = 'unfulfilled_collection_or_ambiguous_evidence';
        }
        if (! $payment->isApplied() && app(PaymentCheckoutState::class)->state($payment) === 'awaiting') {
            $reasons[] = 'actionable_checkout';
        }
        if (! $payment->isApplied() && $payment->fib_payment_id && app(PaymentCheckoutState::class)->state($payment) === 'review') {
            $reasons[] = 'remote_checkout_not_proven_closed';
        }

        return $reasons;
    }

    private function historicalSubscription(object $row): bool
    {
        $meta = json_decode($row->meta ?? '{}', true) ?: [];
        $superseded = ! empty($meta['superseded_at']) && ! $this->futureOrInvalid($meta['superseded_at']);
        if (! in_array($row->status, ['ended', 'expired', 'superseded'], true) && ! $superseded) {
            return false;
        }
        if ($row->auto_renew || ! empty($meta['cancel_at_period_end']) || ! empty($meta['application_review']) || ! empty($meta['requires_review'])) {
            return false;
        }
        if ($this->futureObligation($meta)) {
            return false;
        }
        if (! empty($meta['scheduled_change']) && (empty($meta['scheduled_change']['effective_at']) || $this->futureOrInvalid($meta['scheduled_change']['effective_at']))) {
            return false;
        }
        foreach (['starts_at', 'ends_at', 'cycle_ends_on', 'next_renewal_on'] as $field) {
            if ($this->futureOrInvalid($row->$field)) {
                return false;
            }
        }

        return true;
    }

    private function futureObligation(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $key = strtolower(str_replace('_', '', (string) $key));
            if (is_array($value) && $this->futureObligation($value)) {
                return true;
            }
            if (in_array($key, ['activeuntil', 'paidthrough', 'paiduntil', 'verifiedpaidthrough', 'periodendsat', 'provideractiveuntil', 'cancelat', 'cancelson'], true) && $this->futureOrInvalid($value)) {
                return true;
            }
            if ($key === 'cancelatperiodend' && $value) {
                return true;
            }
        }

        return false;
    }

    private function futureOrInvalid(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $parsed = \App\Domain\Payments\Support\FibSubscriptionTimestamp::parse($value);
        if (! $parsed) {
            return true;
        }
        if (is_int($value) || ctype_digit($value)) {
            return $parsed->greaterThanOrEqualTo(now());
        }
        try {
            $date = CarbonImmutable::parse($value, config('app.timezone'));
            if (strlen($value) === 10) {
                $date = $date->endOfDay();
            }

            // Provider strings without offsets use UTC; normalized SQL dates use
            // application time. Ambiguity must never shorten an obligation.
            return $date->greaterThanOrEqualTo(now()) || $parsed->greaterThanOrEqualTo(now());
        } catch (\Throwable) {
            return true;
        }
    }

    private function transactionOrder(): ?array
    {
        $parents = DB::table('payment_transactions')->orderBy('id')->pluck('parent_transaction_id', 'id')->all();
        $order = [];
        while ($parents) {
            $leaves = array_diff(array_keys($parents), array_filter(array_values($parents)));
            if (! $leaves) {
                return null;
            }
            sort($leaves);
            foreach ($leaves as $id) {
                $order[] = $id;
                unset($parents[$id]);
            }
        }

        return $order;
    }

    /** Full-row multiset digest: covers same-count updates and avoids exposing raw payloads. */
    private function fingerprint(string $table, array $delete = [], array $detach = []): string
    {
        $delete = array_fill_keys($delete, true);
        $detach = array_fill_keys($detach, true);
        $hashes = [];
        foreach (DB::table($table)->cursor() as $row) {
            if (isset($delete[$row->id ?? ''])) {
                continue;
            }
            $data = (array) $row;
            if (isset($detach[$row->id ?? ''])) {
                $data['payment_id'] = null;
            }
            ksort($data);
            $hashes[] = $this->hash($data);
        }
        sort($hashes);

        return hash('sha256', implode('', $hashes));
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

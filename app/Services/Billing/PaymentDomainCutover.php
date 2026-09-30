<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Services\Admin\AdminAudit;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One reviewed business algorithm; deployment identity and preflight are separate policies. */
class PaymentDomainCutover
{
    public const PROCESSING = ['payment_events', 'payment_webhook_events', 'payment_transactions', 'payments', 'payment_intents'];

    private const LINKS = ['credit_orders', 'customer_service_subscriptions', 'customer_storage_subscriptions', 'coupon_redemptions', 'ad_conversion_events'];

    private const SUBSCRIPTIONS = ['customer_service_subscriptions', 'customer_storage_subscriptions'];

    public function identity(string $target): array
    {
        return app(Cutover\CutoverIdentity::class)->inspect($target);
    }

    public function review(string $target, ?int $adminId = null): array
    {
        $this->identity($target);
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET TRANSACTION READ ONLY');
        }

        return DB::transaction(fn () => $this->inspect($target, $adminId));
    }

    public function execute(string $target, string $hash, string $reason, bool $workersStopped, ?int $adminId = null, bool $backupConfirmed = false, bool $restoreConfirmed = false): array
    {
        $this->identity($target);
        $this->authorize($workersStopped);
        if ($target === 'production' && (! $backupConfirmed || ! $restoreConfirmed || $adminId !== auth('admin')->id())) {
            throw new PaymentHistoryResetRefused('Production requires the reviewed Admin and explicit backup/restore confirmations.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash) || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000) {
            throw new PaymentHistoryResetRefused('A reviewed hash and substantive reason are required.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($target, $hash, $reason, $workersStopped, $adminId) {
            foreach (array_unique(['customers', 'payments', ...$this->tables()]) as $table) {
                foreach (DB::table($table)->lockForUpdate()->cursor() as $ignored) {
                }
            }
            $this->authorize($workersStopped);
            $review = $this->inspect($target, $adminId);
            if ($review['blockers'] || ! hash_equals($review['review_hash'], $hash)) {
                throw new PaymentHistoryResetRefused('Cutover refused: blockers or changed review. Run the dry run again.');
            }
            $boundary = ['starts_at' => now()->toDateTimeString(), 'credit_order_id' => DB::table('credit_orders')->max('id') ?? 0,
                'payment_id' => DB::table('payments')->max('id') ?? 0,
                'provider_coverage_ids' => array_keys($review['patches']['provider_coverage_dispositions'] ?? [])];
            $audit = new AdminAudit;
            $audit->reason = $reason;
            $audit->record(BillingReportingBoundary::ACTION, self::class, $hash, [], ['review_hash' => $hash],
                ['reporting_boundary' => $boundary, 'manifest' => $review]);
            $ids = DB::table('admin_audit_events')->where('action', BillingReportingBoundary::ACTION)
                ->where('target_type', self::class)->where('target_id', $hash)->pluck('id')->all();
            if (count($ids) !== 1 || $this->fingerprint('admin_audit_events', [], $ids) !== $review['fingerprints']['admin_audit_events']) {
                throw new PaymentHistoryResetRefused('Existing Admin history changed; cutover rolled back.');
            }
            $auditHash = $this->fingerprint('admin_audit_events');
            foreach ($review['patches'] as $table => $rows) {
                foreach ($rows as $id => $patch) {
                    if (DB::table($table)->where('id', $id)->update($patch) !== 1) {
                        throw new PaymentHistoryResetRefused('Reviewed dependency changed; cutover rolled back.');
                    }
                }
            }
            foreach ($review['delete_order'] as $table) {
                $selfReference = collect(Schema::getForeignKeys($table))->contains(fn ($key) => $key['foreign_table'] === $table);
                foreach (array_chunk($review['delete_ids'][$table], $selfReference ? 1 : 500) as $idsToDelete) {
                    DB::table($table)->whereIn('id', $idsToDelete)->delete();
                }
            }
            foreach ($review['expected_after'] as $table => $expected) {
                if ($this->fingerprint($table) !== ($table === 'admin_audit_events' ? $auditHash : $expected)) {
                    throw new PaymentHistoryResetRefused('Exact preservation check failed; cutover rolled back.');
                }
            }
            $this->verifyEffectivePlans($review['effective_after']);
            $this->authorize($workersStopped);
            if (app(BillingReportingBoundary::class)->apply(DB::table('credit_orders'))->exists()
                || collect(self::PROCESSING)->contains(fn ($table) => DB::table($table)->exists())) {
                throw new PaymentHistoryResetRefused('Current billing did not start empty; cutover rolled back.');
            }

            return ['target' => $target, 'review_hash' => $hash, 'deleted' => $review['delete_counts'], 'reporting_boundary' => $boundary,
                'effective_after' => $review['effective_after'], 'preservation' => 'all expected full-row fingerprints matched',
                'current_revenue' => 0, 'current_online_sales' => 0, 'current_payment_count' => 0, 'audit_id' => $ids[0]];
        });
    }

    private function authorize(bool $workersStopped): void
    {
        AdminAccess::authorize('admin.finance');
        AdminAccess::authorize('admin.reconcile');
        if (! $workersStopped || ! app()->isDownForMaintenance()) {
            throw new PaymentHistoryResetRefused('Maintenance and stopped workers/schedulers/callbacks/writers are required.');
        }
    }

    private function tables(): array
    {
        $tables = Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false);
        sort($tables);

        return $tables;
    }

    private function inspect(string $target, ?int $adminId): array
    {
        $identity = $this->identity($target);
        $readiness = app(Cutover\CutoverReadiness::class)->inspect($target, $adminId);
        $tables = $this->tables();
        foreach ([...self::PROCESSING, ...self::SUBSCRIPTIONS, 'customers', 'credit_orders', 'credit_wallets', 'credit_ledgers',
            'subscription_credit_allocations', 'admin_audit_events', 'service_plan_agreements'] as $required) {
            if (! in_array($required, $tables, true)) {
                throw new PaymentHistoryResetRefused('Required migrated billing schema is missing.');
            }
        }
        $blockers = $patches = $detach = $archive = $schema = $counts = $fingerprints = $expected = [];
        $blockers = $readiness['blockers'];
        if (app(BillingReportingBoundary::class)->current()) {
            $blockers[] = 'This database already has a billing cutover; repeating the cutover is refused.';
        }
        $delete = [];
        foreach (self::PROCESSING as $table) {
            $delete[$table] = DB::table($table)->orderBy('id')->pluck('id')->all();
        }
        // All five processing tables start empty. Corroborated fake/manual provenance
        // survives in the immutable cutover audit, never as live V2 processing rows.
        $archivedIntents = DB::table('payment_intents')->get()->filter(fn ($intent) => LegacyFakeIntentEvidence::matches($intent));
        $legacyProvenance = $archivedIntents->map(fn ($intent) => [
            'intent' => array_intersect_key((array) $intent, array_flip(['id', 'uuid', 'customer_id', 'provider', 'payment_method',
                'purpose_type', 'purpose_id', 'status', 'paid_at', 'fulfilled_at', 'base_amount_iqd', 'gross_amount_iqd',
                'currency', 'recurring_strategy', 'provider_payment_id', 'provider_transaction_id'])),
            'order_ids' => DB::table('credit_orders')->where('payment_intent_id', $intent->id)->pluck('id')->all(),
            'transactions' => DB::table('payment_transactions')->where('payment_intent_id', $intent->id)->orderBy('id')->get()
                ->map(fn ($r) => array_intersect_key((array) $r, array_flip(['id', 'payment_intent_id', 'parent_transaction_id',
                    'provider', 'transaction_type', 'status', 'amount_iqd', 'gross_amount_iqd', 'surcharge_amount_iqd',
                    'provider_fee_amount_iqd', 'net_amount_iqd', 'currency', 'processed_at', 'failed_at', 'created_at', 'updated_at'])))->all(),
            'webhook_ids' => DB::table('payment_webhook_events')->where('payment_intent_id', $intent->id)->orderBy('id')->pluck('id')->all(),
        ])->values()->all();
        foreach ($tables as $table) {
            $columns = Schema::getColumns($table);
            $keys = Schema::getForeignKeys($table);
            $schema[$table] = ['columns' => $columns, 'keys' => $keys, 'indexes' => Schema::getIndexes($table)];
            // Include undeclared compatibility links, but never rewrite immutable ledger/allocation evidence.
            foreach (['payment_id' => 'payments', 'payment_intent_id' => 'payment_intents'] as $column => $parent) {
                if (in_array($column, array_column($columns, 'name'), true)
                    && ! collect($keys)->contains(fn ($key) => $key['columns'] === [$column] && $key['foreign_table'] === $parent)) {
                    $keys[] = ['columns' => [$column], 'foreign_columns' => ['id'], 'foreign_table' => $parent];
                }
            }
            foreach ($keys as $key) {
                if (in_array($key['foreign_table'], self::PROCESSING, true) && in_array($table, self::PROCESSING, true)
                    && count($key['columns']) === 1 && $key['foreign_columns'] === ['id']
                    && DB::table($table)->whereNotIn('id', $delete[$table])->whereIn($key['columns'][0], $delete[$key['foreign_table']])->exists()) {
                    $blockers[] = $table.': retained financial history references processing rows selected for retirement';
                }
                if (! in_array($key['foreign_table'], self::PROCESSING, true) || in_array($table, self::PROCESSING, true)) {
                    continue;
                }
                if (count($key['columns']) !== 1 || $key['foreign_columns'] !== ['id']) {
                    $blockers[] = $table.': unsupported composite payment-domain reference';

                    continue;
                }
                $column = $key['columns'][0];
                $nullable = collect($columns)->firstWhere('name', $column)['nullable'];
                foreach (DB::table($table)->whereIn($column, $delete[$key['foreign_table']])->orderBy('id')->get() as $row) {
                    if (! in_array($table, self::LINKS, true) || ! $nullable || ! in_array($column, ['payment_id', 'payment_intent_id'], true)) {
                        $blockers[] = $table.':'.$row->id.': retained evidence cannot be detached from '.$column;

                        continue;
                    }
                    $detach[$table][] = ['id' => $row->id, 'column' => $column, 'original_id' => $row->$column, 'target_table' => $key['foreign_table']];
                    $patches[$table][$row->id][$column] = null;
                }
            }
            foreach (['source', 'related', 'reference'] as $prefix) {
                if (! in_array($prefix.'_type', array_column($columns, 'name'), true) || ! in_array($prefix.'_id', array_column($columns, 'name'), true)) {
                    continue;
                }
                foreach (['payments' => Payment::class, 'payment_intents' => \App\Models\PaymentIntent::class] as $parent => $class) {
                    foreach (DB::table($table)->whereIn($prefix.'_type', array_unique([$class, (new $class)->getMorphClass(), $parent, rtrim($parent, 's')]))
                        ->whereIn($prefix.'_id', DB::table($parent)->select('id'))->orderBy('id')->get() as $row) {
                        if (! in_array($table, self::PROCESSING, true)) {
                            $archive[] = ['table' => $table, 'id' => $row->id, 'column' => $prefix.'_id', 'original_id' => $row->{$prefix.'_id'}, 'target_table' => $parent];
                        }
                    }
                }
            }
        }
        [$normalizations, $effective, $preserved] = $this->subscriptions($patches, $blockers);
        $obligations = app(Cutover\ProviderObligationInventory::class)->inspect($target, $normalizations);
        $blockers = array_merge($blockers, $obligations['blockers']);
        foreach ($patches as $table => &$rows) {
            foreach ($rows as $id => &$patch) {
                $original = (array) DB::table($table)->find($id);
                $patch = array_filter($patch, fn ($value, $column) => $original[$column] !== $value, ARRAY_FILTER_USE_BOTH);
                if (! $patch) {
                    unset($rows[$id]);
                }
            }
            unset($patch);
        }
        unset($rows);
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
            $fingerprints[$table] = $this->fingerprint($table);
            $expected[$table] = $this->fingerprint($table, $patches[$table] ?? [], $delete[$table] ?? []);
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (Schema::getTables(Schema::getCurrentSchemaName()) as $table) {
                if (strtolower($table['engine'] ?? '') !== 'innodb') {
                    $blockers[] = $table['name'].': nontransactional table';
                }
            }
            if (DB::select('SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND TABLE_SCHEMA<>DATABASE() LIMIT 1')) {
                $blockers[] = 'Cross-schema foreign keys require separate review.';
            }
            $triggers = DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME');
        } else {
            $triggers = DB::select("SELECT name FROM sqlite_master WHERE type='trigger' ORDER BY name");
        }
        // A trigger can write outside the inventoried schema or perform an external side effect.
        if ($triggers) {
            $blockers[] = 'Database triggers require explicit preservation review before cutover.';
        }
        $order = $this->deleteOrder($schema, $delete, $blockers);
        $wallets = [];
        foreach (['app', 'api'] as $type) {
            $wallets[$type] = (array) DB::table('credit_wallets')->where('wallet_type', $type)
                ->selectRaw('COUNT(*) AS count, COALESCE(SUM(balance_credits),0) AS balance, COALESCE(SUM(subscription_balance_credits),0) AS subscription_balance, COALESCE(SUM(addon_balance_credits),0) AS addon_balance')->first();
        }
        $result = ['policy' => 'payment-domain-cutover-v2', 'target' => $target, 'database' => $identity,
            'archived_financial_intent_ids' => $archivedIntents->pluck('id')->all(), 'legacy_processing_provenance' => $legacyProvenance,
            'readiness' => $readiness, 'provider_obligations' => $obligations, 'counts' => $counts,
            'reporting_boundary_projection' => ['starts_at' => 'transaction commit time',
                'credit_order_id' => DB::table('credit_orders')->max('id') ?? 0, 'payment_id' => DB::table('payments')->max('id') ?? 0],
            'delete_counts' => array_map('count', $delete), 'delete_ids' => $delete, 'delete_order' => $order,
            'payments' => DB::table('payments')->orderBy('id')->get(['id', 'uuid', 'customer_id', 'provider', 'status', 'internal_status', 'created_at'])->map(fn ($row) => (array) $row)->all(),
            'detach_links' => $detach, 'archived_polymorphic_links' => $archive, 'normalizations' => $normalizations,
            'patches' => $patches, 'effective_after' => $effective, 'preserved_local_access' => $preserved,
            'wallet_totals' => $wallets, 'ledger_count' => $counts['credit_ledgers'],
            'fully_verified_customers' => DB::table('customers')->where('email_verify', 1)->where('phone_verify', 1)->count(),
            'revenue_before' => ['paid_order_count' => \App\Models\CreditOrder::revenueIncluded()->where('status', 'paid')->count(),
                'base_amount_iqd' => (string) \App\Models\CreditOrder::revenueIncluded()->where('status', 'paid')->sum('base_amount_iqd')],
            'fingerprints' => $fingerprints, 'expected_after' => $expected, 'schema_hash' => $this->hash($schema), 'blockers' => $blockers];
        $result['review_hash'] = $this->hash($result);

        return $result;
    }

    private function subscriptions(array &$patches, array &$blockers): array
    {
        $normalized = $effective = $preserved = [];
        $resolver = new CustomerBillingStateService;
        $defaults = ['service' => $resolver->defaultServicePlan(), 'storage' => $resolver->defaultStoragePlan()];
        foreach (self::SUBSCRIPTIONS as $table) {
            $kind = $table === self::SUBSCRIPTIONS[0] ? 'service' : 'storage';
            $model = $kind === 'service' ? CustomerServiceSubscription::class : CustomerStorageSubscription::class;
            $planColumn = $kind.'_plan_id';
            $plans = DB::table($kind.'_plans')->get()->keyBy('id');
            $rows = DB::table($table)->orderBy('id')->get();
            $eligible = app(BillingSubscriptionAuthority::class)->apply($model::effectiveAt(),
                boundary: ['starts_at' => now()->toDateTimeString()])->pluck('id')->all();
            $selected = [];
            foreach ($rows as $row) {
                $meta = json_decode($row->meta ?? '{}', true, flags: JSON_THROW_ON_ERROR) ?? [];
                $local = in_array($row->source, ['admin_manual', 'admin_manual_grant', 'admin', 'manual', 'internal_non_revenue', ServiceAgreementLifecycle::SOURCE], true);
                $provider = $row->payment_id !== null || ! empty($row->provider_ref) || in_array($row->source, ['fib', 'areeba', 'provider', 'online'], true)
                    || in_array($row->renewal_strategy, ['provider_schedule', 'provider_token'], true)
                    || ! empty($meta['fib_subscription_id']) || ! empty($meta['payment_id']) || ! empty($meta['provider_ref'])
                    || in_array($meta['billing_source'] ?? null, ['fib', 'areeba'], true);
                if ($local && $provider && $this->legacyManualOrderMatches($row, $meta, $kind)) {
                    // Legacy manual orders reused provider-shaped fields. Matched local provenance
                    // is not an online obligation; keep the entire subscription and order intact.
                    $provider = false;
                }
                $free = (int) $row->$planColumn === (int) $defaults[$kind]->id;
                if ($local && $provider) {
                    $blockers[] = $table.':'.$row->id.': local access has conflicting provider authority';
                } elseif ($provider) {
                    $payment = $row->payment_id ? Payment::find($row->payment_id) : null;
                    $disposition = $payment ? app(ProviderCoverageDispositions::class)->approvedFor($payment) : null;
                    if ($disposition && $disposition->subscription_kind === $kind && (int) $disposition->subscription_id === (int) $row->id) {
                        $start = \App\Domain\Payments\Support\FibSubscriptionTimestamp::parse($disposition->coverage_start)->setTimezone(config('app.timezone'));
                        $end = \App\Domain\Payments\Support\FibSubscriptionTimestamp::parse($disposition->coverage_end)->setTimezone(config('app.timezone'));
                        $patches[$table][$row->id] = array_merge($patches[$table][$row->id] ?? [], [
                            'payment_id' => null, 'status' => 'active', 'auto_renew' => 0,
                            'starts_at' => $start->toDateTimeString(), 'ends_at' => $end->copy()->ceilSecond()->toDateTimeString()]);
                        $patches['provider_coverage_dispositions'][$disposition->id] = ['status' => 'retained'];
                        $normalized[$table][] = ['id' => $row->id, 'customer_id' => $row->customer_id, 'disposition_id' => $disposition->id];
                        $selected[$row->customer_id] = ['subscription_id' => $row->id, 'plan_id' => $row->$planColumn, 'kind' => 'legacy_provider_coverage'];
                        $preserved[$table][] = ['id' => $row->id, 'customer_id' => $row->customer_id, 'disposition_id' => $disposition->id];

                        continue;
                    }
                    $patches[$table][$row->id] = array_merge($patches[$table][$row->id] ?? [], ['status' => 'ended', 'auto_renew' => 0]);
                    // No timestamp, source, provider metadata or remote cancellation status is changed.
                    $normalized[$table][] = ['id' => $row->id, 'customer_id' => $row->customer_id, 'previous_status' => $row->status, 'previous_auto_renew' => $row->auto_renew];

                    continue;
                }
                if (! $free && ! $local && $row->status === 'active'
                    && (! $row->starts_at || $row->starts_at <= now()->toDateTimeString())
                    && (! $row->ends_at || $row->ends_at >= now()->toDateTimeString())) {
                    $blockers[] = $table.':'.$row->id.': unclassified effective paid access';
                }
                if ($local) {
                    $preserved[$table][] = ['id' => $row->id, 'customer_id' => $row->customer_id, 'plan_id' => $row->$planColumn, 'source' => $row->source];
                }
                if ($row->source === ServiceAgreementLifecycle::SOURCE) {
                    $agreement = DB::table('service_plan_agreements')->where('subscription_id', $row->id)->first();
                    if ($kind !== 'service' || ! $agreement || (int) $agreement->customer_id !== (int) $row->customer_id
                        || (int) $agreement->service_plan_id !== (int) $row->$planColumn
                        || (int) ($meta['agreement_id'] ?? 0) !== (int) $agreement->id
                        || $agreement->starts_at !== $row->starts_at || $agreement->ends_at !== $row->ends_at) {
                        $blockers[] = $table.':'.$row->id.': inconsistent external agreement binding';
                    }
                }
                if (in_array($row->id, $eligible, true)) {
                    if (! $free && ! $local) {
                        $blockers[] = $table.':'.$row->id.': unclassified effective paid access';
                    }
                    if (! isset($plans[$row->$planColumn])) {
                        $blockers[] = $table.':'.$row->id.': plan is missing';
                    }
                    $selected[$row->customer_id] = ['subscription_id' => $row->id, 'plan_id' => $row->$planColumn,
                        'kind' => $free ? 'free' : ($row->source === ServiceAgreementLifecycle::SOURCE ? 'external' : 'complimentary')];
                }
            }
            foreach (DB::table('customers')->orderBy('id')->pluck('id') as $id) {
                $choice = $selected[$id] ?? ['subscription_id' => null, 'plan_id' => $defaults[$kind]->id, 'kind' => 'free'];
                if ($choice['kind'] !== 'free' && $rows->contains(fn ($row) => (int) $row->customer_id === (int) $id && $row->id > $choice['subscription_id'])) {
                    $blockers[] = $table.':'.$choice['subscription_id'].': newer history conflicts with retained local lifecycle authority';
                }
                $effective[$kind][$id] = $choice;
            }
        }

        return [$normalized, $effective, $preserved];
    }

    private function legacyManualOrderMatches(object $row, array $meta, string $kind): bool
    {
        if ($kind !== 'service' || $row->source !== 'admin_manual' || ($meta['provider'] ?? null) !== 'admin_manual'
            || $row->payment_id !== null || empty($row->provider_ref) || ! empty($meta['payment_id'])
            || ! empty($meta['fib_subscription_id']) || ! empty($meta['provider_ref'])
            || in_array($meta['billing_source'] ?? null, ['fib', 'areeba'], true)) {
            return false;
        }
        $order = DB::table('credit_orders')->where('id', $meta['order_id'] ?? 0)->first();

        return $order && $order->provider === 'admin_manual' && $order->payment_method === 'admin_manual'
            && $order->payment_id === null && $order->payment_intent_id === null
            && (int) $order->customer_id === (int) $row->customer_id
            && (int) $order->service_plan_id === (int) $row->service_plan_id && $order->provider_ref === $row->provider_ref
            && ! DB::table('payments')->where('fib_subscription_id', $row->provider_ref)->orWhere('fib_payment_id', $row->provider_ref)->exists();
    }

    private function verifyEffectivePlans(array $expected): void
    {
        $resolver = new CustomerBillingStateService;
        foreach (Customer::orderBy('id')->cursor() as $customer) {
            foreach (['service', 'storage'] as $kind) {
                $subscription = $kind === 'service' ? $resolver->resolveActiveServiceSubscription($customer) : $resolver->resolveActiveStorageSubscription($customer);
                $default = $kind === 'service' ? $resolver->defaultServicePlan() : $resolver->defaultStoragePlan();
                if ((int) ($subscription?->{$kind.'_plan_id'} ?? $default->id) !== (int) $expected[$kind][$customer->id]['plan_id']
                    || $subscription?->id !== $expected[$kind][$customer->id]['subscription_id']) {
                    throw new PaymentHistoryResetRefused('Effective plan differs from the reviewed projection; cutover rolled back.');
                }
            }
        }
    }

    private function deleteOrder(array $schema, array &$ids, array &$blockers): array
    {
        $remaining = self::PROCESSING;
        $order = [];
        while ($remaining) {
            $parents = [];
            foreach ($remaining as $table) {
                foreach ($schema[$table]['keys'] as $key) {
                    if ($key['foreign_table'] !== $table && in_array($key['foreign_table'], $remaining, true)) {
                        $parents[] = $key['foreign_table'];
                    }
                }
            }
            $leaves = array_values(array_diff($remaining, $parents));
            if (! $leaves) {
                $blockers[] = 'Cyclic payment-domain foreign keys require separate review.';
                break;
            }
            foreach ($leaves as $table) {
                $order[] = $table;
                $remaining = array_values(array_diff($remaining, [$table]));
                foreach ($schema[$table]['keys'] as $key) {
                    if ($key['foreign_table'] !== $table) {
                        continue;
                    }
                    if (count($key['columns']) !== 1 || $key['foreign_columns'] !== ['id']) {
                        $blockers[] = 'Unsupported composite self-reference: '.$table;

                        continue;
                    }
                    $parentsById = DB::table($table)->orderBy('id')->pluck($key['columns'][0], 'id')->all();
                    $sorted = [];
                    while ($parentsById) {
                        $leafIds = array_diff(array_keys($parentsById), array_filter(array_values($parentsById)));
                        if (! $leafIds) {
                            $blockers[] = 'Cyclic payment row references: '.$table;
                            break;
                        }
                        sort($leafIds);
                        foreach ($leafIds as $id) {
                            $sorted[] = $id;
                            unset($parentsById[$id]);
                        }
                    }
                    $ids[$table] = array_values(array_intersect($sorted, $ids[$table]));
                }
            }
        }

        return $order;
    }

    /** SHA256 full-row multiset, with exact reviewed patches projected without writes. */
    private function fingerprint(string $table, array $patches = [], array $exclude = []): string
    {
        $exclude = array_fill_keys($exclude, true);
        $hashes = [];
        foreach (DB::table($table)->cursor() as $row) {
            if (isset($exclude[$row->id ?? ''])) {
                continue;
            }
            $data = array_replace((array) $row, $patches[$row->id ?? ''] ?? []);
            ksort($data);
            $hashes[] = $this->hash($data);
        }
        sort($hashes);

        return hash('sha256', implode('', $hashes));
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

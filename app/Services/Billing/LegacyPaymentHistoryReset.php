<?php

namespace App\Services\Billing;

use App\Models\CreditOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Retire only abandoned, unfulfilled attempts. Never infer that a purchase was fictitious. */
class LegacyPaymentHistoryReset
{
    public const TABLES = ['payments', 'payment_events', 'payment_intents', 'payment_transactions',
        'payment_webhook_events', 'credit_orders', 'coupon_redemptions', 'customer_service_subscriptions',
        'customer_storage_subscriptions', 'subscription_credit_allocations'];

    public function inspect(string $before): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $dependencies = [];
        $foreignKeys = $this->foreignKeys();
        foreach ($foreignKeys as $table => $keys) {
            foreach ($keys as $fk) {
                if (in_array($fk['foreign_table'], ['payments', 'payment_intents', 'credit_orders'], true)) {
                    $dependencies[] = ['table' => $table, 'columns' => $fk['columns'], 'parent' => $fk['foreign_table'],
                        'parent_columns' => $fk['foreign_columns'], 'on_delete' => $fk['on_delete']];
                }
            }
        }
        $states = [
            // Deliberately include stale status=active rows: expiry is not permission to erase their evidence.
            'active_nonfree_service' => DB::table('customer_service_subscriptions as s')->join('service_plans as p', 'p.id', '=', 's.service_plan_id')->where('s.status', 'active')->where('p.is_free', false)->count(),
            'active_paid_storage' => DB::table('customer_storage_subscriptions as s')->join('storage_plans as p', 'p.id', '=', 's.storage_plan_id')->where('s.status', 'active')->where(fn ($q) => $q->where('p.price_iqd', '>', 0)->orWhere('p.price_usd', '>', 0))->count(),
            'unresolved_payments' => DB::table('payments')->where(fn ($q) => $q->whereNotIn('status', ['paid', 'failed', 'canceled', 'expired', 'refunded'])->orWhereNull('status')
                ->orWhereNotIn('internal_status', ['applied', 'failed', 'canceled', 'expired', 'refunded'])->orWhereNull('internal_status'))->count(),
            'unapplied_paid_payments' => DB::table('payments')->where('status', 'paid')->whereNull('fulfilled_at')->count(),
            'fulfilled_payments' => DB::table('payments')->whereNotNull('fulfilled_at')->count(),
            'unresolved_intents' => DB::table('payment_intents')->where(fn ($q) => $q->whereNotIn('status', ['paid', 'failed', 'canceled', 'expired', 'refunded'])->orWhereNull('status'))->count(),
            'unapplied_paid_intents' => DB::table('payment_intents')->where('status', 'paid')->whereNull('fulfilled_at')->count(),
            'fulfilled_intents' => DB::table('payment_intents')->whereNotNull('fulfilled_at')->count(),
            'provider_subscription_references' => DB::table('payments')->whereNotNull('fib_subscription_id')->where('fib_subscription_id', '<>', '')->count(),
        ];
        $blockers = [];
        foreach (['active_nonfree_service', 'active_paid_storage', 'unresolved_payments', 'unapplied_paid_payments', 'unresolved_intents', 'unapplied_paid_intents'] as $key) {
            if ($states[$key]) {
                $blockers[] = $key.': '.$states[$key];
            }
        }
        // A reset of existing purchase evidence requires a separately approved financial disposition.
        if ($states['fulfilled_payments'] || $states['fulfilled_intents'] || $counts['credit_orders'] || $counts['subscription_credit_allocations']) {
            $blockers[] = 'Retained purchases/orders/allocations require an explicit financial disposition; no override is provided.';
        }
        $orders = ['paid_provider_purchase' => [], 'manual_non_revenue' => [], 'addon_purchase' => [], 'historical_compatibility' => []];
        foreach (CreditOrder::query()->get() as $order) {
            $kind = $order->isRevenueExcluded() || in_array($order->provider, ['admin_manual', 'admin_manual_grant'], true)
                ? 'manual_non_revenue' : ($order->source_type === 'credit_product' ? 'addon_purchase'
                    : ($order->provider === 'fib' && $order->status === 'paid' ? 'paid_provider_purchase' : 'historical_compatibility'));
            $orders[$kind][] = $order->id;
        }
        $delete = ['payment_events' => [], 'payment_webhook_events' => [], 'payment_transactions' => [], 'payments' => [], 'payment_intents' => []];
        $retained = [];
        foreach (['payments', 'payment_intents'] as $parent) {
            $query = DB::table($parent)->where('created_at', '<', $before)->whereIn('status', ['failed', 'canceled', 'expired'])->whereNull('paid_at')->whereNull('fulfilled_at');
            if ($parent === 'payments') {
                $query->whereIn('internal_status', ['failed', 'canceled', 'expired'])->whereNull('last_payment_at')->whereNull('fib_subscription_id');
            } else {
                $query->whereNull('authorized_at')->whereNull('refunded_at')->whereNull('provider_schedule_ref');
            }
            foreach ($query->orderBy('id')->get() as $row) {
                $children = $parent === 'payments' ? ['payment_events'] : ['payment_transactions', 'payment_webhook_events'];
                $referenced = false;
                foreach ($dependencies as $fk) {
                    if ($fk['parent'] !== $parent || in_array($fk['table'], $children, true)) {
                        continue;
                    }
                    if ($fk['parent_columns'] !== ['id'] || count($fk['columns']) !== 1
                        || DB::table($fk['table'])->where($fk['columns'][0], $row->id)->exists()) {
                        $referenced = true;
                    }
                }
                // Financial provenance may be polymorphic or embedded in JSON, without a foreign key.
                // Retain all attempts of customers with ledger evidence instead of guessing its attribution.
                if (DB::table('credit_ledgers')->where('customer_id', $row->customer_id)->exists()
                    || DB::table('credit_wallets')->where('customer_id', $row->customer_id)->where('balance_credits', '<>', 0)->exists()) {
                    $referenced = true;
                }
                if ($parent === 'payment_intents' && DB::table('payment_transactions')->where('payment_intent_id', $row->id)->whereNotIn('status', ['failed', 'canceled', 'expired'])->exists()) {
                    $referenced = true;
                }
                if ($referenced) {
                    $retained[$parent][] = $row->id;

                    continue;
                }
                $delete[$parent][] = $row->id;
                foreach ($children as $child) {
                    $column = $parent === 'payments' ? 'payment_id' : 'payment_intent_id';
                    $delete[$child] = array_merge($delete[$child], DB::table($child)->where($column, $row->id)->pluck('id')->all());
                }
            }
        }
        // Self-references or newly introduced dependent tables must not cascade/null retained records.
        foreach ($delete as $table => $ids) {
            if (! $ids) {
                continue;
            }
            foreach ($foreignKeys as $child => $keys) {
                foreach ($keys as $fk) {
                    if ($fk['foreign_table'] !== $table) {
                        continue;
                    }
                    if ($fk['foreign_columns'] !== ['id'] || count($fk['columns']) !== 1) {
                        $blockers[] = 'Unsupported dependency: '.$child.' -> '.$table;

                        continue;
                    }
                    if (DB::table($child)->whereIn($fk['columns'][0], $ids)->whereNotIn('id', $delete[$child] ?? [])->exists()) {
                        $blockers[] = 'Retained dependency: '.$child.' -> '.$table;
                    }
                }
            }
        }
        foreach ($delete as &$ids) {
            sort($ids);
        }
        unset($ids);
        $result = ['before' => $before, 'counts' => $counts, 'states' => $states, 'dependencies' => $dependencies,
            'order_classification_ids' => $orders, 'candidate_delete_ids' => $delete, 'retained_dependency_ids' => $retained,
            'wallet_totals' => DB::table('credit_wallets')->selectRaw('wallet_type, COUNT(*) AS wallets, SUM(balance_credits) AS balance, SUM(subscription_balance_credits) AS subscription_balance, SUM(addon_balance_credits) AS addon_balance')->groupBy('wallet_type')->orderBy('wallet_type')->get()->toArray(),
            'ledger_count' => DB::table('credit_ledgers')->count(), 'blockers' => array_values(array_unique($blockers))];
        $result['review_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function deleteReviewed(array $review): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.finance');
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');
        if (! DB::transactionLevel() || $review['blockers']) {
            throw new \LogicException('A locked, unblocked review is required.');
        }
        $current = $this->inspect($review['before']);
        if ($current['blockers'] || ! hash_equals($current['review_hash'], $review['review_hash'])) {
            throw new \LogicException('The reviewed boundary changed.');
        }
        // Never trust a caller-supplied list of deletion IDs.
        $review = $current;
        foreach (['payment_events', 'payment_webhook_events', 'payment_transactions', 'payments', 'payment_intents'] as $table) {
            $ids = $review['candidate_delete_ids'][$table];
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->delete();
            }
        }
    }

    private function foreignKeys(): array
    {
        $keys = [];
        if (DB::connection()->getDriverName() === 'mysql') {
            // One schema-scoped metadata read; do not repeatedly scan information_schema per table.
            $rows = DB::select('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
            foreach ($rows as $r) {
                $key = &$keys[$r->TABLE_NAME][$r->CONSTRAINT_NAME];
                $key['columns'][] = $r->COLUMN_NAME;
                $key['foreign_columns'][] = $r->REFERENCED_COLUMN_NAME;
                $key['foreign_table'] = $r->REFERENCED_TABLE_NAME;
                $key['on_delete'] = strtolower($r->DELETE_RULE);
                unset($key);
            }
        } else {
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                $keys[$table] = Schema::getForeignKeys($table);
            }
        }

        // Some compatibility tables retain an ID without a physical FK. Protect those too.
        $parents = ['payment_id' => 'payments', 'payment_intent_id' => 'payment_intents', 'credit_order_id' => 'credit_orders'];
        $columns = DB::connection()->getDriverName() === 'mysql'
            ? DB::select("SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('payment_id','payment_intent_id','credit_order_id') ORDER BY TABLE_NAME,COLUMN_NAME")
            : collect(Schema::getTableListing(schemaQualified: false))->flatMap(fn ($table) => collect(Schema::getColumnListing($table))
                ->filter(fn ($column) => isset($parents[$column]))->map(fn ($column) => (object) ['table_name' => $table, 'column_name' => $column]))->all();
        foreach ($columns as $column) {
            $parent = $parents[$column->column_name];
            if (! collect($keys[$column->table_name] ?? [])->contains(fn ($fk) => $fk['columns'] === [$column->column_name] && $fk['foreign_table'] === $parent)) {
                $keys[$column->table_name][] = ['columns' => [$column->column_name], 'foreign_columns' => ['id'],
                    'foreign_table' => $parent, 'on_delete' => 'retain_logical_reference'];
            }
        }

        return $keys;
    }
}

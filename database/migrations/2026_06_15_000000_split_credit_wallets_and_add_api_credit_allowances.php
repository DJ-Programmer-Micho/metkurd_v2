<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_plans', 'app_monthly_credits')) {
                $table->unsignedBigInteger('app_monthly_credits')->nullable();
            }

            if (! Schema::hasColumn('service_plans', 'api_monthly_credits')) {
                $table->unsignedBigInteger('api_monthly_credits')->default(0);
            }
        });

        DB::table('service_plans')
            ->whereNull('app_monthly_credits')
            ->update([
                'app_monthly_credits' => DB::raw('monthly_credits'),
            ]);

        $paidTools = json_encode([
            'tts:apollo-1-0v',
            'tts:apollo-1-5v',
            'tts:delta-1-0v',
            'tts:vector-1-0',
            'tts:vector-1-5',
            'asr:wasr',
            'asr:qasr',
            'caption:qasr',
            'ocr:generate',
            'translation:generate',
            'stem:generate',
            'usage:read',
        ], JSON_THROW_ON_ERROR);

        foreach ([
            'free' => [
                'monthly_credits' => 10000,
                'app_monthly_credits' => 10000,
                'api_monthly_credits' => 0,
                'concurrent_jobs_limit' => 1,
                'api_enabled' => false,
                'api_requests_per_minute' => 0,
                'api_concurrent_jobs' => 0,
                'api_allowed_tools' => json_encode([], JSON_THROW_ON_ERROR),
            ],
            'student' => [
                'monthly_credits' => 100000,
                'app_monthly_credits' => 100000,
                'api_monthly_credits' => 150000,
                'concurrent_jobs_limit' => 2,
                'api_enabled' => true,
                'api_requests_per_minute' => 60,
                'api_concurrent_jobs' => 2,
                'api_allowed_tools' => $paidTools,
            ],
            'pro' => [
                'monthly_credits' => 250000,
                'app_monthly_credits' => 250000,
                'api_monthly_credits' => 300000,
                'concurrent_jobs_limit' => 3,
                'api_enabled' => true,
                'api_requests_per_minute' => 300,
                'api_concurrent_jobs' => 10,
                'api_allowed_tools' => $paidTools,
            ],
            'premium' => [
                'monthly_credits' => 600000,
                'app_monthly_credits' => 600000,
                'api_monthly_credits' => 800000,
                'concurrent_jobs_limit' => 5,
                'api_enabled' => true,
                'api_requests_per_minute' => 1000,
                'api_concurrent_jobs' => 50,
                'api_allowed_tools' => $paidTools,
            ],
        ] as $code => $attributes) {
            DB::table('service_plans')
                ->where('code', $code)
                ->update($attributes);
        }

        Schema::table('credit_wallets', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_wallets', 'wallet_type')) {
                $table->string('wallet_type', 20)->default('app')->index();
            }
        });

        DB::table('credit_wallets')
            ->where(function ($query): void {
                $query->whereNull('wallet_type')->orWhere('wallet_type', '');
            })
            ->update(['wallet_type' => 'app']);

        $this->ensureCreditWalletIndexes();

        $this->backfillApiWallets();

        Schema::table('credit_ledgers', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_ledgers', 'wallet_type')) {
                $table->string('wallet_type', 20)->default('app')->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'source_type')) {
                $table->string('source_type', 40)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'source_id')) {
                $table->string('source_id', 150)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'direction')) {
                $table->string('direction', 20)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'amount')) {
                $table->unsignedBigInteger('amount')->nullable();
            }

            if (! Schema::hasColumn('credit_ledgers', 'balance_before')) {
                $table->unsignedBigInteger('balance_before')->nullable();
            }

            if (! Schema::hasColumn('credit_ledgers', 'tool_code')) {
                $table->string('tool_code', 60)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'tool_action')) {
                $table->string('tool_action', 130)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'metric_code')) {
                $table->string('metric_code', 50)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'metric_quantity')) {
                $table->decimal('metric_quantity', 14, 4)->nullable();
            }

            if (! Schema::hasColumn('credit_ledgers', 'api_key_id')) {
                $table->unsignedBigInteger('api_key_id')->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'api_job_id')) {
                $table->string('api_job_id', 40)->nullable()->index();
            }

            if (! Schema::hasColumn('credit_ledgers', 'ml_job_id')) {
                $table->uuid('ml_job_id')->nullable()->index();
            }
        });

        DB::table('credit_ledgers')
            ->where(function ($query): void {
                $query->whereNull('wallet_type')->orWhere('wallet_type', '');
            })
            ->update(['wallet_type' => 'app']);

        DB::table('credit_ledgers')->update([
            'amount' => DB::raw('ABS(credits_delta)'),
            'balance_before' => DB::raw('CASE WHEN balance_after IS NULL THEN NULL ELSE balance_after - credits_delta END'),
        ]);

        DB::table('credit_ledgers')
            ->whereNull('direction')
            ->update([
                'direction' => DB::raw("
                    CASE
                        WHEN type = 'api_reserve' THEN 'reserve'
                        WHEN type = 'api_reservation_release' THEN 'release'
                        WHEN credits_delta < 0 THEN 'debit'
                        ELSE 'credit'
                    END
                "),
            ]);

        DB::table('credit_ledgers')
            ->whereNull('source_type')
            ->update([
                'source_type' => DB::raw("
                    CASE
                        WHEN type IN ('monthly_grant', 'monthly_refill', 'plan_reset') THEN 'subscription_refill'
                        WHEN type = 'addon_purchase' THEN 'addon'
                        WHEN type = 'api_reserve' THEN 'public_api'
                        WHEN type = 'api_reservation_release' THEN 'refund'
                        WHEN type LIKE '%refund%' THEN 'refund'
                        ELSE 'web_tool'
                    END
                "),
            ]);
    }

    public function down(): void
    {
        DB::table('credit_wallets')
            ->where('wallet_type', 'api')
            ->delete();

        $creditWalletCustomerIdIndex = 'credit_wallets_customer_id_index';
        $creditWalletCustomerIdUnique = 'credit_wallets_customer_id_unique';
        $creditWalletCustomerWalletTypeLegacyUnique = 'credit_wallets_customer_wallet_type_unique';
        $creditWalletCustomerWalletTypeUnique = 'credit_wallets_customer_id_wallet_type_unique';

        if ($this->indexExists('credit_wallets', $creditWalletCustomerWalletTypeUnique)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerWalletTypeUnique): void {
                $table->dropUnique($creditWalletCustomerWalletTypeUnique);
            });
        }

        if ($this->indexExists('credit_wallets', $creditWalletCustomerWalletTypeLegacyUnique)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerWalletTypeLegacyUnique): void {
                $table->dropUnique($creditWalletCustomerWalletTypeLegacyUnique);
            });
        }

        Schema::table('credit_wallets', function (Blueprint $table): void {
            if (Schema::hasColumn('credit_wallets', 'wallet_type')) {
                $table->dropColumn('wallet_type');
            }
        });

        if (! $this->indexExists('credit_wallets', $creditWalletCustomerIdUnique)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerIdUnique): void {
                $table->unique('customer_id', $creditWalletCustomerIdUnique);
            });
        }

        if ($this->indexExists('credit_wallets', $creditWalletCustomerIdIndex)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerIdIndex): void {
                $table->dropIndex($creditWalletCustomerIdIndex);
            });
        }

        Schema::table('credit_ledgers', function (Blueprint $table): void {
            foreach ([
                'wallet_type',
                'source_type',
                'source_id',
                'direction',
                'amount',
                'balance_before',
                'tool_code',
                'tool_action',
                'metric_code',
                'metric_quantity',
                'api_key_id',
                'api_job_id',
                'ml_job_id',
            ] as $column) {
                if (Schema::hasColumn('credit_ledgers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('service_plans', function (Blueprint $table): void {
            foreach (['app_monthly_credits', 'api_monthly_credits'] as $column) {
                if (Schema::hasColumn('service_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    protected function backfillApiWallets(): void
    {
        $now = now();

        $activeSubscriptions = DB::table('customer_service_subscriptions')
            ->selectRaw('MAX(id) as id')
            ->where('status', 'active')
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->groupBy('customer_id');

        $planState = DB::table('customer_service_subscriptions as subscriptions')
            ->joinSub($activeSubscriptions, 'active_subscriptions', function ($join): void {
                $join->on('subscriptions.id', '=', 'active_subscriptions.id');
            })
            ->leftJoin('service_plans', 'service_plans.id', '=', 'subscriptions.service_plan_id')
            ->select([
                'subscriptions.customer_id',
                'service_plans.api_enabled',
                'service_plans.api_monthly_credits',
            ]);

        $rows = DB::table('credit_wallets as app_wallet')
            ->leftJoinSub($planState, 'plan_state', function ($join): void {
                $join->on('plan_state.customer_id', '=', 'app_wallet.customer_id');
            })
            ->leftJoin('credit_wallets as api_wallet', function ($join): void {
                $join->on('api_wallet.customer_id', '=', 'app_wallet.customer_id')
                    ->where('api_wallet.wallet_type', '=', 'api');
            })
            ->where('app_wallet.wallet_type', 'app')
            ->whereNull('api_wallet.id')
            ->orderBy('app_wallet.id')
            ->get([
                'app_wallet.customer_id',
                'app_wallet.cycle_started_on',
                'app_wallet.cycle_ends_on',
                'app_wallet.current_cycle_key',
                'app_wallet.last_granted_at',
                'app_wallet.created_at',
                'app_wallet.updated_at',
                'plan_state.api_enabled',
                'plan_state.api_monthly_credits',
            ]);

        collect($rows)
            ->chunk(200)
            ->each(function ($chunk) use ($now): void {
                $payload = $chunk->map(function ($row) use ($now): array {
                    $subscriptionCredits = (bool) ($row->api_enabled ?? false)
                        ? max(0, (int) ($row->api_monthly_credits ?? 0))
                        : 0;

                    return [
                        'customer_id' => (int) $row->customer_id,
                        'wallet_type' => 'api',
                        'balance_credits' => $subscriptionCredits,
                        'subscription_balance_credits' => $subscriptionCredits,
                        'addon_balance_credits' => 0,
                        'lifetime_earned' => $subscriptionCredits,
                        'lifetime_spent' => 0,
                        'lifetime_refunded' => 0,
                        'cycle_started_on' => $row->cycle_started_on ?: $now->copy()->startOfMonth()->toDateString(),
                        'cycle_ends_on' => $row->cycle_ends_on ?: $now->copy()->endOfMonth()->toDateString(),
                        'current_cycle_key' => $row->current_cycle_key ?: $now->format('Y-m'),
                        'last_granted_at' => $subscriptionCredits > 0 ? ($row->last_granted_at ?: $now) : null,
                        'last_charged_at' => null,
                        'created_at' => $row->created_at ?: $now,
                        'updated_at' => $row->updated_at ?: $now,
                    ];
                })->all();

                if ($payload !== []) {
                    DB::table('credit_wallets')->insert($payload);
                }
            });
    }

    protected function ensureCreditWalletIndexes(): void
    {
        $creditWalletCustomerIdIndex = 'credit_wallets_customer_id_index';
        $creditWalletCustomerIdUnique = 'credit_wallets_customer_id_unique';
        $creditWalletCustomerWalletTypeLegacyUnique = 'credit_wallets_customer_wallet_type_unique';
        $creditWalletCustomerWalletTypeUnique = 'credit_wallets_customer_id_wallet_type_unique';

        if (! $this->indexExists('credit_wallets', $creditWalletCustomerIdIndex)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerIdIndex): void {
                $table->index('customer_id', $creditWalletCustomerIdIndex);
            });
        }

        if (
            $this->indexExists('credit_wallets', $creditWalletCustomerWalletTypeLegacyUnique)
            && ! $this->indexExists('credit_wallets', $creditWalletCustomerWalletTypeUnique)
        ) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerWalletTypeLegacyUnique): void {
                $table->dropUnique($creditWalletCustomerWalletTypeLegacyUnique);
            });
        }

        if ($this->indexExists('credit_wallets', $creditWalletCustomerIdUnique)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerIdUnique): void {
                $table->dropUnique($creditWalletCustomerIdUnique);
            });
        }

        if (! $this->indexExists('credit_wallets', $creditWalletCustomerWalletTypeUnique)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerWalletTypeUnique): void {
                $table->unique(['customer_id', 'wallet_type'], $creditWalletCustomerWalletTypeUnique);
            });
        }

        if ($this->indexExists('credit_wallets', $creditWalletCustomerWalletTypeLegacyUnique)) {
            Schema::table('credit_wallets', function (Blueprint $table) use ($creditWalletCustomerWalletTypeLegacyUnique): void {
                $table->dropUnique($creditWalletCustomerWalletTypeLegacyUnique);
            });
        }
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        return match ($driver) {
            'mysql' => DB::table('information_schema.statistics')
                ->where('table_schema', Schema::getConnection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($index): bool => (string) ($index->name ?? '') === $indexName),
            'pgsql' => DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists(),
            default => false,
        };
    }
};

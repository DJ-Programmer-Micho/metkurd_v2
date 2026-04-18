<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BillingCurrencyBootstrapSeeder extends Seeder
{
    /**
     * Seed only the managed anchor pairs used by the chained-rate model:
     * IQD -> USD and USD -> target quotes.
     *
     * Derived IQD -> target pairs are computed at runtime by BillingCurrencyService
     * and should not be seeded as direct managed rows.
     */
    public function run(): void
    {
        $now = now();
        $usdAnchoredQuotes = ['EUR', 'GBP', 'CAD', 'AUD', 'CHF'];

        DB::table('currency_exchange_rates')
            ->where(function ($query) use ($usdAnchoredQuotes) {
                $query
                    ->where(function ($inner) {
                        $inner
                            ->where('base_currency_code', 'IQD')
                            ->where('quote_currency_code', 'USD');
                    })
                    ->orWhere(function ($inner) use ($usdAnchoredQuotes) {
                        $inner
                            ->where('base_currency_code', 'USD')
                            ->whereIn('quote_currency_code', $usdAnchoredQuotes);
                    });
            })
            ->update(['is_current' => false, 'updated_at' => $now]);

        DB::table('currency_exchange_rates')->upsert([
            [
                'base_currency_code' => 'IQD',
                'quote_currency_code' => 'USD',
                'rate' => round(1 / 1500, 8),
                'source' => 'bootstrap',
                'effective_at' => $now,
                'expires_at' => null,
                'is_current' => true,
                'meta' => json_encode(['note' => 'Bootstrap IQD to USD anchor rate']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'base_currency_code' => 'USD',
                'quote_currency_code' => 'EUR',
                'rate' => 0.87,
                'source' => 'bootstrap',
                'effective_at' => $now,
                'expires_at' => null,
                'is_current' => true,
                'meta' => json_encode(['note' => 'Bootstrap USD anchor rate']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'base_currency_code' => 'USD',
                'quote_currency_code' => 'GBP',
                'rate' => 0.79,
                'source' => 'bootstrap',
                'effective_at' => $now,
                'expires_at' => null,
                'is_current' => true,
                'meta' => json_encode(['note' => 'Bootstrap USD anchor rate']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'base_currency_code' => 'USD',
                'quote_currency_code' => 'CAD',
                'rate' => 1.39,
                'source' => 'bootstrap',
                'effective_at' => $now,
                'expires_at' => null,
                'is_current' => true,
                'meta' => json_encode(['note' => 'Bootstrap USD anchor rate']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'base_currency_code' => 'USD',
                'quote_currency_code' => 'AUD',
                'rate' => 1.46,
                'source' => 'bootstrap',
                'effective_at' => $now,
                'expires_at' => null,
                'is_current' => true,
                'meta' => json_encode(['note' => 'Bootstrap USD anchor rate']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'base_currency_code' => 'USD',
                'quote_currency_code' => 'CHF',
                'rate' => 0.88,
                'source' => 'bootstrap',
                'effective_at' => $now,
                'expires_at' => null,
                'is_current' => true,
                'meta' => json_encode(['note' => 'Bootstrap USD anchor rate']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['base_currency_code', 'quote_currency_code', 'effective_at'], ['rate', 'source', 'expires_at', 'is_current', 'meta', 'updated_at']);
    }
}

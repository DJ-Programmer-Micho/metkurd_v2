<?php

namespace App\Console\Commands;

use App\Models\CreditWallet;
use Illuminate\Console\Command;

class RepairCreditWalletBuckets extends Command
{
    protected $signature = 'credits:repair-wallet-buckets';

    protected $description = 'Repair old credit wallets where balance_credits exists but bucket balances are empty';

    public function handle(): int
    {
        $fixed = 0;

        CreditWallet::query()
            ->where('wallet_type', CreditWallet::TYPE_APP)
            ->chunkById(200, function ($wallets) use (&$fixed) {
                foreach ($wallets as $wallet) {
                    $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
                    $addon = (int) ($wallet->addon_balance_credits ?? 0);
                    $balance = (int) ($wallet->balance_credits ?? 0);

                    if (($subscription + $addon) === 0 && $balance > 0) {
                        $wallet->addon_balance_credits = $balance;
                        $wallet->balance_credits = $balance;
                        $wallet->save();

                        $fixed++;

                        $this->line("Fixed wallet #{$wallet->id} for customer {$wallet->customer_id} (balance: {$balance})");
                    }
                }
            });

        $this->info("Done. Fixed {$fixed} wallet(s).");

        return self::SUCCESS;
    }
}

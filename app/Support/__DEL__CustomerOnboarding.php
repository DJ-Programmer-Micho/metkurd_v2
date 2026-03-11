<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerUsage;
use App\Models\CreditWallet;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\CreditMonthlyGrant;
use App\Models\CreditLedger;
use Illuminate\Support\Facades\DB;

class CustomerOnboarding
{
    /**
     * Ensure all defaults exist for a new customer:
     * - customer_usage row
     * - credit_wallet row
     * - default active service subscription (free)
     * - default active storage subscription (free-512)
     * - monthly credits grant for current month (credits expire monthly)
     */
    public static function provision(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {

            // 1) Usage row
            CustomerUsage::firstOrCreate(
                ['customer_id' => $customer->id],
                ['storage_used_bytes' => 0, 'jobs_total' => 0, 'jobs_succeeded' => 0, 'jobs_failed' => 0]
            );

            // 2) Wallet row
            $wallet = CreditWallet::firstOrCreate(
                ['customer_id' => $customer->id],
                [
                    'balance_credits' => 0,
                    'lifetime_earned' => 0,
                    'lifetime_spent' => 0,
                    'cycle_started_on' => now()->startOfMonth()->toDateString(),
                    'cycle_ends_on' => now()->endOfMonth()->toDateString(),
                ]
            );

            // 3) Active service subscription (free)
            $freeService = ServicePlan::where('code', 'free')->first();
            if ($freeService) {
                CustomerServiceSubscription::updateOrCreate(
                    ['customer_id' => $customer->id, 'status' => 'active'],
                    [
                        'service_plan_id' => $freeService->id,
                        'starts_at' => now(),
                        'cycle_started_on' => now()->startOfMonth()->toDateString(),
                        'cycle_ends_on' => now()->endOfMonth()->toDateString(),
                        'previous_service_plan_id' => null,
                        'upgraded_at' => null,
                        'meta' => null,
                    ]
                );

                // 4) Monthly credit grant (once per month)
                $yearMonth = now()->format('Y-m');

                $alreadyGranted = CreditMonthlyGrant::where('customer_id', $customer->id)
                    ->where('year_month', $yearMonth)
                    ->exists();

                if (!$alreadyGranted) {
                    CreditMonthlyGrant::create([
                        'customer_id' => $customer->id,
                        'service_plan_id' => $freeService->id,
                        'year_month' => $yearMonth,
                        'granted_credits' => $freeService->monthly_credits,
                        'granted_at' => now(),
                    ]);

                    // Ledger + wallet increment
                    $wallet->balance_credits += (int) $freeService->monthly_credits;
                    $wallet->lifetime_earned += (int) $freeService->monthly_credits;
                    $wallet->save();

                    CreditLedger::create([
                        'customer_id' => $customer->id,
                        'type' => 'monthly_grant',
                        'credits_delta' => (int) $freeService->monthly_credits,
                        'balance_after' => (int) $wallet->balance_credits,
                        'related_type' => CreditMonthlyGrant::class,
                        'related_id' => (string) $yearMonth,
                        'meta' => ['plan_code' => 'free', 'year_month' => $yearMonth],
                        'created_at' => now(),
                    ]);
                }
            }

            // 5) Active storage subscription (free-512)
            $freeStorage = StoragePlan::where('code', 'free-512')->first();
            if ($freeStorage) {
                CustomerStorageSubscription::updateOrCreate(
                    ['customer_id' => $customer->id, 'status' => 'active'],
                    [
                        'storage_plan_id' => $freeStorage->id,
                        'starts_at' => now(),
                        'meta' => null,
                    ]
                );
            }
        });
    }
}
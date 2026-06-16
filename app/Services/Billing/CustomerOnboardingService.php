<?php

namespace App\Services\Billing;

use App\Models\CreditLedger;
use App\Models\CreditMonthlyGrant;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\CustomerUsage;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerOnboardingService
{
    public function provisionDefaults(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $registeredAt = $customer->created_at?->copy() ?? now();

            $servicePlan = $this->resolveDefaultServicePlan();
            $storagePlan = $this->resolveDefaultStoragePlan();
            $servicePlanAmountIqd = $servicePlan->priceIqdForCycle('monthly');
            $storagePlanAmountIqd = $storagePlan->priceIqdAmount();
            $servicePlanSnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd(
                $servicePlanAmountIqd,
                $customer,
            );
            $storagePlanSnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd(
                $storagePlanAmountIqd,
                $customer,
            );

            CustomerUsage::firstOrCreate(
                ['customer_id' => $customer->id],
                [
                    'storage_used_bytes' => 0,
                    'jobs_total' => 0,
                    'jobs_succeeded' => 0,
                    'jobs_failed' => 0,
                ]
            );

            $serviceSubscription = CustomerServiceSubscription::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'status' => 'active',
                ],
                [
                    'service_plan_id' => $servicePlan->id,
                    'starts_at' => $registeredAt,
                    'cycle_started_on' => $registeredAt->copy()->startOfMonth()->toDateString(),
                    'cycle_ends_on' => $registeredAt->copy()->endOfMonth()->toDateString(),
                    'previous_service_plan_id' => null,
                    'upgraded_at' => null,
                    'price_iqd_snapshot' => $servicePlanAmountIqd,
                    'display_currency_code' => $servicePlanSnapshot['display_currency_code'],
                    'display_exchange_rate' => $servicePlanSnapshot['display_exchange_rate'],
                    'display_amount_raw' => $servicePlanSnapshot['display_amount_raw'],
                    'display_amount_rounded' => $servicePlanSnapshot['display_amount_rounded'],
                    'display_rounding_step' => $servicePlanSnapshot['display_rounding_step'],
                    'display_rounding_mode' => $servicePlanSnapshot['display_rounding_mode'],
                    'display_country_code' => $servicePlanSnapshot['display_country_code'],
                    'meta' => [
                        'display_label' => $servicePlanSnapshot['display_label'],
                        'base_label' => $servicePlanSnapshot['base_label'],
                        'iqd_label' => $servicePlanSnapshot['iqd_label'],
                        'usd_reference_label' => $servicePlanSnapshot['usd_reference_label'],
                        'currency_resolution_source' => $servicePlanSnapshot['currency_resolution_source'],
                    ],
                ]
            );

            CustomerStorageSubscription::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'status' => 'active',
                ],
                [
                    'storage_plan_id' => $storagePlan->id,
                    'price_iqd_snapshot' => $storagePlanAmountIqd,
                    'display_currency_code' => $storagePlanSnapshot['display_currency_code'],
                    'display_exchange_rate' => $storagePlanSnapshot['display_exchange_rate'],
                    'display_amount_raw' => $storagePlanSnapshot['display_amount_raw'],
                    'display_amount_rounded' => $storagePlanSnapshot['display_amount_rounded'],
                    'display_rounding_step' => $storagePlanSnapshot['display_rounding_step'],
                    'display_rounding_mode' => $storagePlanSnapshot['display_rounding_mode'],
                    'display_country_code' => $storagePlanSnapshot['display_country_code'],
                    'starts_at' => $registeredAt,
                    'meta' => [
                        'display_label' => $storagePlanSnapshot['display_label'],
                        'base_label' => $storagePlanSnapshot['base_label'],
                        'iqd_label' => $storagePlanSnapshot['iqd_label'],
                        'usd_reference_label' => $storagePlanSnapshot['usd_reference_label'],
                        'currency_resolution_source' => $storagePlanSnapshot['currency_resolution_source'],
                    ],
                ]
            );

            $wallet = CreditWallet::firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'wallet_type' => CreditWallet::TYPE_APP,
                ],
                CreditWallet::defaultAttributes((int) $customer->id, CreditWallet::TYPE_APP, $registeredAt)
            );

            $apiWallet = CreditWallet::firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'wallet_type' => CreditWallet::TYPE_API,
                ],
                CreditWallet::defaultAttributes((int) $customer->id, CreditWallet::TYPE_API, $registeredAt)
            );

            $yearMonth = $registeredAt->format('Y-m');

            $alreadyGranted = CreditMonthlyGrant::query()
                ->where('customer_id', $customer->id)
                ->where('year_month', $yearMonth)
                ->exists();

            if ($alreadyGranted) {
                return;
            }

            $grant = (int) $servicePlan->appMonthlyCredits();
            $apiGrant = (int) $servicePlan->apiMonthlyCredits();

            $monthlyGrant = CreditMonthlyGrant::create([
                'customer_id' => $customer->id,
                'service_plan_id' => $servicePlan->id,
                'subscription_id' => $serviceSubscription->id,
                'year_month' => $yearMonth,
                'granted_credits' => $grant,
                'granted_at' => $registeredAt,
                'meta' => [
                    'plan_code' => $servicePlan->code,
                    'app_granted_credits' => $grant,
                    'api_granted_credits' => $apiGrant,
                ],
            ]);

            $appBalanceBefore = (int) ($wallet->balance_credits ?? 0);
            $wallet->subscription_balance_credits = (int) $wallet->subscription_balance_credits + $grant;
            $wallet->syncCombinedBalance();
            $wallet->lifetime_earned = (int) $wallet->lifetime_earned + $grant;
            $wallet->cycle_started_on = $registeredAt->copy()->startOfMonth()->toDateString();
            $wallet->cycle_ends_on = $registeredAt->copy()->endOfMonth()->toDateString();
            $wallet->current_cycle_key = $yearMonth;
            $wallet->last_granted_at = $registeredAt;
            $wallet->save();

            $apiBalanceBefore = (int) ($apiWallet->balance_credits ?? 0);

            if ($apiGrant > 0) {
                $apiWallet->subscription_balance_credits = (int) $apiWallet->subscription_balance_credits + $apiGrant;
                $apiWallet->syncCombinedBalance();
                $apiWallet->lifetime_earned = (int) $apiWallet->lifetime_earned + $apiGrant;
                $apiWallet->cycle_started_on = $registeredAt->copy()->startOfMonth()->toDateString();
                $apiWallet->cycle_ends_on = $registeredAt->copy()->endOfMonth()->toDateString();
                $apiWallet->current_cycle_key = $yearMonth;
                $apiWallet->last_granted_at = $registeredAt;
                $apiWallet->save();
            }

            CreditLedger::create([
                'customer_id' => $customer->id,
                'wallet_type' => CreditWallet::TYPE_APP,
                'type' => 'monthly_grant',
                'source_type' => 'subscription_refill',
                'direction' => 'credit',
                'amount' => $grant,
                'bucket' => 'subscription',
                'credits_delta' => $grant, // positive credit
                'balance_before' => $appBalanceBefore,
                'balance_after' => (int) $wallet->balance_credits,
                'subscription_balance_after' => (int) $wallet->subscription_balance_credits,
                'addon_balance_after' => (int) $wallet->addon_balance_credits,
                'related_type' => CreditMonthlyGrant::class,
                'related_id' => (string) $monthlyGrant->id,
                'source_id' => (string) $monthlyGrant->id,
                'reference_code' => 'monthly_grant:'.$customer->id.':'.$yearMonth,
                'meta' => [
                    'plan_code' => $servicePlan->code,
                    'year_month' => $yearMonth,
                    'subscription_id' => $serviceSubscription->id,
                    'app_granted_credits' => $grant,
                    'api_granted_credits' => $apiGrant,
                ],
                'created_at' => $registeredAt,
            ]);

            if ($apiGrant > 0) {
                CreditLedger::create([
                    'customer_id' => $customer->id,
                    'wallet_type' => CreditWallet::TYPE_API,
                    'type' => 'monthly_grant',
                    'source_type' => 'subscription_refill',
                    'source_id' => (string) $monthlyGrant->id,
                    'direction' => 'credit',
                    'amount' => $apiGrant,
                    'bucket' => 'subscription',
                    'credits_delta' => $apiGrant,
                    'balance_before' => $apiBalanceBefore,
                    'balance_after' => (int) $apiWallet->balance_credits,
                    'subscription_balance_after' => (int) $apiWallet->subscription_balance_credits,
                    'addon_balance_after' => (int) $apiWallet->addon_balance_credits,
                    'related_type' => CreditMonthlyGrant::class,
                    'related_id' => (string) $monthlyGrant->id,
                    'reference_code' => 'monthly_grant:api:'.$customer->id.':'.$yearMonth,
                    'meta' => [
                        'plan_code' => $servicePlan->code,
                        'year_month' => $yearMonth,
                        'subscription_id' => $serviceSubscription->id,
                        'app_granted_credits' => $grant,
                        'api_granted_credits' => $apiGrant,
                    ],
                    'created_at' => $registeredAt,
                ]);
            }
        });
    }

    protected function resolveDefaultServicePlan(): ServicePlan
    {
        $plan = ServicePlan::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('code', 'free')
                    ->orWhere('is_free', true);
            })
            ->orderByRaw("CASE WHEN code = 'free' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($plan instanceof ServicePlan) {
            return $plan;
        }

        throw new RuntimeException('No active default service plan is configured. Expected an active free service plan.');
    }

    protected function resolveDefaultStoragePlan(): StoragePlan
    {
        $plan = StoragePlan::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('code', 'free-512')
                    ->orWhere('price_iqd', 0)
                    ->orWhere('price_usd', 0);
            })
            ->orderByRaw("CASE WHEN code = 'free-512' THEN 0 ELSE 1 END")
            ->orderBy('quota_mb')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($plan instanceof StoragePlan) {
            return $plan;
        }

        throw new RuntimeException('No active default storage plan is configured. Expected an active free storage plan.');
    }
}

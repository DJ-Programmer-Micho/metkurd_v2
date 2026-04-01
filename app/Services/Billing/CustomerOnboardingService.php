<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\CustomerUsage;
use App\Models\CreditLedger;
use App\Models\CreditMonthlyGrant;
use App\Models\CreditWallet;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Illuminate\Support\Facades\DB;

class CustomerOnboardingService
{
    public function provisionDefaults(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $registeredAt = $customer->created_at?->copy() ?? now();

            $servicePlan = ServicePlan::query()
                ->where('code', 'free')
                ->where('is_active', true)
                ->firstOrFail();

            $storagePlan = StoragePlan::query()
                ->where('code', 'free-512')
                ->where('is_active', true)
                ->firstOrFail();
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
                ['customer_id' => $customer->id],
                [
                    'balance_credits' => 0,
                    'subscription_balance_credits' => 0,
                    'addon_balance_credits' => 0,
                    'lifetime_earned' => 0,
                    'lifetime_spent' => 0,
                    'lifetime_refunded' => 0,
                    'cycle_started_on' => $registeredAt->copy()->startOfMonth()->toDateString(),
                    'cycle_ends_on' => $registeredAt->copy()->endOfMonth()->toDateString(),
                    'current_cycle_key' => $registeredAt->format('Y-m'),
                    'last_granted_at' => null,
                    'last_charged_at' => null,
                ]
            );

            $yearMonth = $registeredAt->format('Y-m');

            $alreadyGranted = CreditMonthlyGrant::query()
                ->where('customer_id', $customer->id)
                ->where('year_month', $yearMonth)
                ->exists();

            if ($alreadyGranted) {
                return;
            }

            $grant = (int) $servicePlan->monthly_credits;

            $monthlyGrant = CreditMonthlyGrant::create([
                'customer_id' => $customer->id,
                'service_plan_id' => $servicePlan->id,
                'subscription_id' => $serviceSubscription->id,
                'year_month' => $yearMonth,
                'granted_credits' => $grant,
                'granted_at' => $registeredAt,
                'meta' => [
                    'plan_code' => $servicePlan->code,
                ],
            ]);

            $wallet->subscription_balance_credits = (int) $wallet->subscription_balance_credits + $grant;
            $wallet->balance_credits = (int) $wallet->subscription_balance_credits + (int) $wallet->addon_balance_credits;
            $wallet->lifetime_earned = (int) $wallet->lifetime_earned + $grant;
            $wallet->cycle_started_on = $registeredAt->copy()->startOfMonth()->toDateString();
            $wallet->cycle_ends_on = $registeredAt->copy()->endOfMonth()->toDateString();
            $wallet->current_cycle_key = $yearMonth;
            $wallet->last_granted_at = $registeredAt;
            $wallet->save();

            CreditLedger::create([
                'customer_id' => $customer->id,
                'type' => 'monthly_grant',
                'bucket' => 'subscription',
                'credits_delta' => $grant, // positive credit
                'balance_after' => (int) $wallet->balance_credits,
                'subscription_balance_after' => (int) $wallet->subscription_balance_credits,
                'addon_balance_after' => (int) $wallet->addon_balance_credits,
                'related_type' => CreditMonthlyGrant::class,
                'related_id' => (string) $monthlyGrant->id,
                'reference_code' => 'monthly_grant:' . $customer->id . ':' . $yearMonth,
                'meta' => [
                    'plan_code' => $servicePlan->code,
                    'year_month' => $yearMonth,
                    'subscription_id' => $serviceSubscription->id,
                ],
                'created_at' => $registeredAt,
            ]);
        });
    }
}

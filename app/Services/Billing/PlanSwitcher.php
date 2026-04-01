<?php

namespace App\Services\Billing;

use App\Models\{
    Customer,
    ServicePlan,
    StoragePlan,
    CustomerServiceSubscription,
    CustomerStorageSubscription,
    CreditWallet,
    CreditLedger,
    CreditMonthlyGrant,
    CreditOrder
};
use Illuminate\Support\Facades\DB;

class PlanSwitcher
{
    public function switchServicePlan(Customer $customer, int $servicePlanId, array $meta = []): CustomerServiceSubscription
    {
        return DB::transaction(function () use ($customer, $servicePlanId, $meta) {
            $plan = ServicePlan::where('is_active', true)->findOrFail($servicePlanId);
            $billingCycle = strtolower(trim((string) ($meta['billing_cycle'] ?? 'monthly')));
            $billingCycle = in_array($billingCycle, ['monthly', 'yearly'], true) ? $billingCycle : 'monthly';
            $amountIqd = $plan->priceIqdForCycle($billingCycle);
            $currencySnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd($amountIqd, $customer, $meta);

            $order = CreditOrder::create([
                'customer_id' => $customer->id,
                'order_type' => 'subscription',
                'source_type' => 'service_plan',
                'service_plan_id' => $plan->id,
                'credit_product_id' => null,
                'status' => 'paid',
                'credits_amount' => (int) $plan->monthly_credits,
                'amount_usd' => $currencySnapshot['usd_reference_amount'],
                'currency' => 'IQD',
                'base_currency_code' => 'IQD',
                'base_amount_iqd' => $amountIqd,
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'provider' => 'fake',
                'provider_ref' => 'FAKE-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                'meta' => array_merge([
                    'purpose' => 'service_plan_switch',
                    'billing_cycle' => $billingCycle,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                ], $meta),
            ]);

            $currentSub = $customer->activeServiceSubscription()->first();
            $previousPlanId = $currentSub?->service_plan_id;

            if ($currentSub) {
                $currentSub->update([
                    'status' => 'ended',
                    'ends_at' => now(),
                    'canceled_at' => now(),
                ]);
            }

            $newSub = CustomerServiceSubscription::create([
                'customer_id' => $customer->id,
                'service_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'cycle_started_on' => now()->toDateString(),
                'cycle_ends_on' => now()->addMonth()->toDateString(),
                'previous_service_plan_id' => $previousPlanId,
                'upgraded_at' => now(),
                'source' => 'fake',
                'provider_ref' => $order->provider_ref,
                'next_renewal_on' => now()->addMonth()->toDateString(),
                'auto_renew' => false,
                'price_iqd_snapshot' => $amountIqd,
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'meta' => [
                    'order_id' => $order->id,
                    'provider' => 'fake',
                    'billing_cycle' => $billingCycle,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                ],
            ]);

            /** @var CreditWallet $wallet */
            $wallet = $customer->wallet()->firstOrCreate([], [
                'balance_credits' => 0,
                'subscription_balance_credits' => 0,
                'addon_balance_credits' => 0,
                'lifetime_earned' => 0,
                'lifetime_spent' => 0,
                'lifetime_refunded' => 0,
                'cycle_started_on' => now()->toDateString(),
                'cycle_ends_on' => now()->addMonth()->toDateString(),
                'current_cycle_key' => now()->format('Y-m'),
            ]);

            $oldCombined = (int) $wallet->balance_credits;
            $oldAddon = (int) ($wallet->addon_balance_credits ?? 0);

            $newSubscriptionBalance = (int) $plan->monthly_credits;
            $newCombined = $newSubscriptionBalance + $oldAddon;

            $wallet->update([
                'subscription_balance_credits' => $newSubscriptionBalance,
                'addon_balance_credits' => $oldAddon,
                'balance_credits' => $newCombined,
                'cycle_started_on' => now()->toDateString(),
                'cycle_ends_on' => now()->addMonth()->toDateString(),
                'current_cycle_key' => now()->format('Y-m'),
                'last_granted_at' => now(),
            ]);

            $grant = CreditMonthlyGrant::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'year_month' => now()->format('Y-m'),
                ],
                [
                    'service_plan_id' => $plan->id,
                    'subscription_id' => $newSub->id,
                    'granted_credits' => $newSubscriptionBalance,
                    'granted_at' => now(),
                    'meta' => [
                        'order_id' => $order->id,
                        'plan_code' => $plan->code,
                        'billing_cycle' => $billingCycle,
                    ],
                ]
            );

            $delta = $newCombined - $oldCombined;

            CreditLedger::create([
                'customer_id' => $customer->id,
                'type' => 'plan_reset',
                'bucket' => 'combined',
                'credits_delta' => $delta,
                'balance_after' => $newCombined,
                'subscription_balance_after' => $newSubscriptionBalance,
                'addon_balance_after' => $oldAddon,
                'related_type' => CreditOrder::class,
                'related_id' => (string) $order->id,
                'reference_code' => 'PLAN-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                'meta' => [
                    'plan_code' => $plan->code,
                    'plan_id' => $plan->id,
                    'previous_plan_id' => $previousPlanId,
                    'grant_id' => $grant->id,
                    'order_id' => $order->id,
                    'billing_cycle' => $billingCycle,
                ],
            ]);

            return $newSub;
        }, 3);
    }

    public function switchStoragePlan(Customer $customer, int $storagePlanId, array $meta = []): CustomerStorageSubscription
    {
        return DB::transaction(function () use ($customer, $storagePlanId, $meta) {
            $plan = StoragePlan::where('is_active', true)->findOrFail($storagePlanId);
            $amountIqd = $plan->priceIqdAmount();
            $currencySnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd($amountIqd, $customer, $meta);

            CreditOrder::create([
                'customer_id' => $customer->id,
                'order_type' => 'adjustment',
                'source_type' => 'storage_plan',
                'service_plan_id' => null,
                'credit_product_id' => null,
                'status' => 'paid',
                'credits_amount' => 0,
                'amount_usd' => $currencySnapshot['usd_reference_amount'],
                'currency' => 'IQD',
                'base_currency_code' => 'IQD',
                'base_amount_iqd' => $amountIqd,
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'provider' => 'fake',
                'provider_ref' => 'FAKE-STORAGE-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                'meta' => array_merge([
                    'purpose' => 'storage_plan_change',
                    'storage_plan_id' => $plan->id,
                    'storage_plan_code' => $plan->code,
                    'storage_plan_name' => $plan->name,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                ], $meta),
            ]);

            $current = $customer->activeStorageSubscription()->first();

            if ($current) {
                $current->update([
                    'status' => 'ended',
                    'ends_at' => now(),
                ]);
            }

            $usedBytes = (int) $customer->storageUsedBytes();
            $quotaBytes = (int) $plan->quota_mb * 1024 * 1024;
            $overQuota = $usedBytes > $quotaBytes;

            return CustomerStorageSubscription::create([
                'customer_id' => $customer->id,
                'storage_plan_id' => $plan->id,
                'status' => 'active',
                'price_iqd_snapshot' => $amountIqd,
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'starts_at' => now(),
                'meta' => [
                    'over_quota' => $overQuota,
                    'used_bytes' => $usedBytes,
                    'quota_bytes' => $quotaBytes,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                ],
            ]);
        }, 3);
    }
}

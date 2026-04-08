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
            $provider = (string) ($meta['provider'] ?? 'fake');
            $providerRef = (string) ($meta['provider_ref'] ?? ('FAKE-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)));
            $paymentMethod = (string) ($meta['payment_method'] ?? $provider);
            $paymentIntentId = $meta['payment_intent_id'] ?? null;
            $merchantTransactionId = $meta['merchant_transaction_id'] ?? null;
            $providerTransactionId = $meta['provider_transaction_id'] ?? null;
            $grossAmount = (int) ($meta['gross_amount_iqd'] ?? $amountIqd);
            $surchargeAmount = (int) ($meta['surcharge_amount_iqd'] ?? 0);
            $providerFeeAmount = (int) ($meta['provider_fee_amount_iqd'] ?? 0);
            $netAmount = (int) ($meta['net_amount_iqd'] ?? max(0, $grossAmount - $providerFeeAmount));
            $customerPaymentMethodId = $meta['customer_payment_method_id'] ?? null;
            $renewalStrategy = (string) ($meta['renewal_strategy'] ?? 'manual_renewal');
            $nextRenewalOn = $billingCycle === 'yearly'
                ? now()->addYear()->toDateString()
                : now()->addMonth()->toDateString();

            $order = CreditOrder::create([
                'customer_id' => $customer->id,
                'payment_intent_id' => $paymentIntentId,
                'order_type' => 'subscription',
                'source_type' => 'service_plan',
                'service_plan_id' => $plan->id,
                'credit_product_id' => null,
                'status' => 'paid',
                'status_reason' => null,
                'credits_amount' => (int) $plan->monthly_credits,
                'amount_usd' => $currencySnapshot['usd_reference_amount'],
                'currency' => 'IQD',
                'base_currency_code' => 'IQD',
                'base_amount_iqd' => $amountIqd,
                'gross_amount_iqd' => $grossAmount,
                'surcharge_amount_iqd' => $surchargeAmount,
                'provider_fee_amount_iqd' => $providerFeeAmount,
                'net_amount_iqd' => $netAmount,
                'fee_currency_code' => 'IQD',
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'provider' => $provider,
                'payment_method' => $paymentMethod,
                'provider_ref' => $providerRef,
                'merchant_transaction_id' => $merchantTransactionId,
                'provider_transaction_id' => $providerTransactionId,
                'paid_at' => $meta['paid_at'] ?? now(),
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
                'cycle_ends_on' => $nextRenewalOn,
                'previous_service_plan_id' => $previousPlanId,
                'upgraded_at' => now(),
                'source' => $provider,
                'provider_ref' => $order->provider_ref,
                'next_renewal_on' => $nextRenewalOn,
                'auto_renew' => $customerPaymentMethodId !== null && $renewalStrategy !== 'manual_renewal',
                'customer_payment_method_id' => $customerPaymentMethodId,
                'renewal_strategy' => $renewalStrategy,
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
                    'provider' => $provider,
                    'billing_cycle' => $billingCycle,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                    'merchant_transaction_id' => $merchantTransactionId,
                    'provider_transaction_id' => $providerTransactionId,
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
            $provider = (string) ($meta['provider'] ?? 'fake');
            $providerRef = (string) ($meta['provider_ref'] ?? ('FAKE-STORAGE-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)));
            $paymentMethod = (string) ($meta['payment_method'] ?? $provider);
            $paymentIntentId = $meta['payment_intent_id'] ?? null;
            $merchantTransactionId = $meta['merchant_transaction_id'] ?? null;
            $providerTransactionId = $meta['provider_transaction_id'] ?? null;
            $grossAmount = (int) ($meta['gross_amount_iqd'] ?? $amountIqd);
            $surchargeAmount = (int) ($meta['surcharge_amount_iqd'] ?? 0);
            $providerFeeAmount = (int) ($meta['provider_fee_amount_iqd'] ?? 0);
            $netAmount = (int) ($meta['net_amount_iqd'] ?? max(0, $grossAmount - $providerFeeAmount));
            $customerPaymentMethodId = $meta['customer_payment_method_id'] ?? null;
            $renewalStrategy = (string) ($meta['renewal_strategy'] ?? 'manual_renewal');

            $order = CreditOrder::create([
                'customer_id' => $customer->id,
                'payment_intent_id' => $paymentIntentId,
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
                'gross_amount_iqd' => $grossAmount,
                'surcharge_amount_iqd' => $surchargeAmount,
                'provider_fee_amount_iqd' => $providerFeeAmount,
                'net_amount_iqd' => $netAmount,
                'fee_currency_code' => 'IQD',
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'provider' => $provider,
                'payment_method' => $paymentMethod,
                'provider_ref' => $providerRef,
                'merchant_transaction_id' => $merchantTransactionId,
                'provider_transaction_id' => $providerTransactionId,
                'paid_at' => $meta['paid_at'] ?? now(),
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
                'source' => $provider,
                'provider_ref' => $order->provider_ref,
                'cycle_started_on' => now()->toDateString(),
                'cycle_ends_on' => now()->addMonth()->toDateString(),
                'next_renewal_on' => now()->addMonth()->toDateString(),
                'auto_renew' => $customerPaymentMethodId !== null && $renewalStrategy !== 'manual_renewal',
                'customer_payment_method_id' => $customerPaymentMethodId,
                'renewal_strategy' => $renewalStrategy,
                'meta' => [
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'over_quota' => $overQuota,
                    'used_bytes' => $usedBytes,
                    'quota_bytes' => $quotaBytes,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                    'merchant_transaction_id' => $merchantTransactionId,
                    'provider_transaction_id' => $providerTransactionId,
                ],
            ]);
        }, 3);
    }
}

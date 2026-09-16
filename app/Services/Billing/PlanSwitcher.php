<?php

namespace App\Services\Billing;

use App\Enums\PaymentRecurringStrategy;
use App\Models\CreditLedger;
use App\Models\CreditMonthlyGrant;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\SubscriptionCreditAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PlanSwitcher
{
    /** The same initial allowance calculation used by fulfillment and read-only purchase previews. */
    public function servicePlanBalanceResult(ServicePlan $plan, int $appAddon, int $apiAddon): array
    {
        return [
            'app' => ['subscription' => $plan->appMonthlyCredits(), 'addon' => $appAddon, 'total' => $plan->appMonthlyCredits() + $appAddon],
            'api' => ['subscription' => $plan->apiMonthlyCredits(), 'addon' => $apiAddon, 'total' => $plan->apiMonthlyCredits() + $apiAddon],
        ];
    }

    public function switchServicePlan(Customer $customer, int $servicePlanId, array $meta = []): CustomerServiceSubscription
    {
        return DB::transaction(function () use ($customer, $servicePlanId, $meta) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $plan = ServicePlan::where('is_active', true)->findOrFail($servicePlanId);
            $billingCycle = $this->normalizeBillingCycle((string) ($meta['billing_cycle'] ?? 'monthly'));
            $catalogAmountIqd = $plan->priceIqdForCycle($billingCycle === 'yearly' ? 'yearly' : 'monthly');
            $originalAmountIqd = (int) ($meta['original_amount_iqd'] ?? $catalogAmountIqd);
            $amountIqd = (int) ($meta['base_amount_iqd'] ?? $meta['discounted_amount_iqd'] ?? $catalogAmountIqd);
            $discountAmountIqd = (int) ($meta['discount_amount_iqd'] ?? max(0, $originalAmountIqd - $amountIqd));
            $couponId = $meta['coupon_id'] ?? null;
            $couponCode = $meta['coupon_code'] ?? null;
            $currencySnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd($amountIqd, $customer, $meta);
            $provider = (string) ($meta['provider'] ?? 'fake');
            $providerRef = (string) ($meta['provider_ref'] ?? ('FAKE-'.now()->format('YmdHis').'-'.random_int(1000, 9999)));
            $paymentMethod = (string) ($meta['payment_method'] ?? $provider);
            $paymentIntentId = $meta['payment_intent_id'] ?? null;
            $paymentId = $meta['payment_id'] ?? null;
            $merchantTransactionId = $meta['merchant_transaction_id'] ?? null;
            $providerTransactionId = $meta['provider_transaction_id'] ?? null;
            $grossAmount = (int) ($meta['gross_amount_iqd'] ?? $amountIqd);
            $surchargeAmount = (int) ($meta['surcharge_amount_iqd'] ?? 0);
            $providerFeeAmount = (int) ($meta['provider_fee_amount_iqd'] ?? 0);
            $netAmount = (int) ($meta['net_amount_iqd'] ?? max(0, $grossAmount - $providerFeeAmount));
            $customerPaymentMethodId = $meta['customer_payment_method_id'] ?? null;
            $renewalStrategy = (string) ($meta['renewal_strategy'] ?? PaymentRecurringStrategy::MANUAL_RENEWAL->value);
            $periodEndsAt = $renewalStrategy === PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                && empty($meta['active_until']) ? null : $this->periodEnd($meta['active_until'] ?? null, $billingCycle);
            $nextRenewalOn = $periodEndsAt?->toDateString();

            $order = CreditOrder::create([
                'customer_id' => $customer->id,
                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'payment_intent_id' => $paymentIntentId,
                'payment_id' => $paymentId,
                'order_type' => 'subscription',
                'source_type' => 'service_plan',
                'service_plan_id' => $plan->id,
                'credit_product_id' => null,
                'status' => 'paid',
                'status_reason' => null,
                'credits_amount' => (int) $plan->appMonthlyCredits(),
                'amount_usd' => $currencySnapshot['usd_reference_amount'],
                'currency' => 'IQD',
                'base_currency_code' => 'IQD',
                'base_amount_iqd' => $amountIqd,
                'original_amount_iqd' => $originalAmountIqd,
                'discount_amount_iqd' => $discountAmountIqd,
                'discounted_amount_iqd' => $amountIqd,
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
                    'coupon' => $meta['coupon'] ?? null,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                ], $meta),
            ]);

            $currentSub = CustomerServiceSubscription::where('customer_id', $customer->id)->latest('id')->lockForUpdate()->first();
            $previousPlanId = $currentSub?->service_plan_id;

            if ($currentSub) {
                $currentMeta = (array) ($currentSub->meta ?? []);
                $currentMeta['superseded_at'] = now()->toIso8601String();
                $currentMeta['superseded_by_service_plan_id'] = $plan->id;
                $currentMeta['superseded_by_service_plan_code'] = $plan->code;
                $currentMeta['superseded_by_payment_id'] = $paymentId;
                $currentMeta['superseded_by_provider_ref'] = $providerRef;
                $currentMeta['previous_paid_through'] = app(SubscriptionCyclePolicy::class)->boundary($currentSub)?->toIso8601String();

                $currentSub->update([
                    'status' => 'ended',
                    'auto_renew' => false,
                    'ends_at' => now(),
                    'canceled_at' => now(),
                    'meta' => $currentMeta,
                ]);
            }

            $newSub = CustomerServiceSubscription::create([
                'customer_id' => $customer->id,
                'payment_id' => $paymentId,
                'coupon_id' => $couponId,
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
                'auto_renew' => $renewalStrategy === PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                    || ($customerPaymentMethodId !== null && $renewalStrategy !== PaymentRecurringStrategy::MANUAL_RENEWAL->value),
                'customer_payment_method_id' => $customerPaymentMethodId,
                'renewal_strategy' => $renewalStrategy,
                'price_iqd_snapshot' => $amountIqd,
                'original_price_iqd_snapshot' => $originalAmountIqd,
                'discount_cycles_consumed' => $couponId !== null ? 1 : 0,
                'display_currency_code' => $currencySnapshot['display_currency_code'],
                'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                'display_country_code' => $currencySnapshot['display_country_code'],
                'meta' => [
                    'order_id' => $order->id,
                    'coupon' => $meta['coupon'] ?? null,
                    'provider' => $provider,
                    'billing_cycle' => $billingCycle,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                    'merchant_transaction_id' => $merchantTransactionId,
                    'provider_transaction_id' => $providerTransactionId,
                    'provider_active_until' => $periodEndsAt?->toIso8601String(),
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                    'provider_last_payment_at' => data_get($meta, 'provider_last_payment_at'),
                    'last_allocated_payment_at' => data_get($meta, 'provider_last_payment_at'),
                    'provider_cycle_key' => data_get($meta, 'provider_cycle_key'),
                ],
            ]);

            /** @var CreditWallet $wallet */
            $wallet = CreditWallet::query()->firstOrCreate(
                [
                    'customer_id' => (int) $customer->id,
                    'wallet_type' => CreditWallet::TYPE_APP,
                ],
                CreditWallet::defaultAttributes((int) $customer->id, CreditWallet::TYPE_APP)
            );

            /** @var CreditWallet $apiWallet */
            $apiWallet = CreditWallet::query()->firstOrCreate(
                [
                    'customer_id' => (int) $customer->id,
                    'wallet_type' => CreditWallet::TYPE_API,
                ],
                CreditWallet::defaultAttributes((int) $customer->id, CreditWallet::TYPE_API)
            );

            $wallet = CreditWallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $apiWallet = CreditWallet::whereKey($apiWallet->id)->lockForUpdate()->firstOrFail();
            if ((bool) ($meta['reset_wallet_balances'] ?? true)) {
                SubscriptionCreditAllocation::create([
                    'customer_id' => $customer->id, 'subscription_id' => $newSub->id,
                    'payment_id' => $paymentId,
                    'cycle_key' => data_get($meta, 'provider_cycle_key') ?: 'initial:'.$newSub->id,
                    'allocation_type' => 'initial',
                    'cycle_started_at' => data_get($meta, 'provider_last_payment_at') ?: now(),
                    'paid_through' => $periodEndsAt, 'status' => 'applied', 'applied_at' => now(),
                ]);
                $oldCombined = (int) $wallet->balance_credits;
                $oldAddon = (int) ($wallet->addon_balance_credits ?? 0);
                $oldApiCombined = (int) ($apiWallet->balance_credits ?? 0);
                $oldApiAddon = (int) ($apiWallet->addon_balance_credits ?? 0);

                $balances = $this->servicePlanBalanceResult($plan, $oldAddon, $oldApiAddon);
                $newSubscriptionBalance = $balances['app']['subscription'];
                $newCombined = $balances['app']['total'];
                $newApiSubscriptionBalance = $balances['api']['subscription'];
                $newApiCombined = $balances['api']['total'];

                $wallet->update([
                    'subscription_balance_credits' => $newSubscriptionBalance,
                    'addon_balance_credits' => $oldAddon,
                    'balance_credits' => $newCombined,
                    'cycle_started_on' => now()->toDateString(),
                    'cycle_ends_on' => now()->addMonth()->toDateString(),
                    'current_cycle_key' => now()->format('Y-m'),
                    'last_granted_at' => now(),
                ]);

                $apiWallet->update([
                    'subscription_balance_credits' => $newApiSubscriptionBalance,
                    'addon_balance_credits' => $oldApiAddon,
                    'balance_credits' => $newApiCombined,
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
                            'app_granted_credits' => $newSubscriptionBalance,
                            'api_granted_credits' => $newApiSubscriptionBalance,
                        ],
                    ]
                );

                $delta = $newCombined - $oldCombined;
                $apiDelta = $newApiCombined - $oldApiCombined;

                CreditLedger::create([
                    'customer_id' => $customer->id,
                    'wallet_type' => CreditWallet::TYPE_APP,
                    'type' => 'plan_reset',
                    'source_type' => 'subscription_refill',
                    'source_id' => (string) $order->id,
                    'direction' => $delta < 0 ? 'debit' : 'credit',
                    'amount' => abs($delta),
                    'bucket' => 'combined',
                    'credits_delta' => $delta,
                    'balance_before' => $oldCombined,
                    'balance_after' => $newCombined,
                    'subscription_balance_after' => $newSubscriptionBalance,
                    'addon_balance_after' => $oldAddon,
                    'related_type' => CreditOrder::class,
                    'related_id' => (string) $order->id,
                    'reference_code' => 'PLAN-'.now()->format('YmdHis').'-'.random_int(1000, 9999),
                    'meta' => [
                        'plan_code' => $plan->code,
                        'plan_id' => $plan->id,
                        'previous_plan_id' => $previousPlanId,
                        'grant_id' => $grant->id,
                        'order_id' => $order->id,
                        'billing_cycle' => $billingCycle,
                        'app_granted_credits' => $newSubscriptionBalance,
                        'api_granted_credits' => $newApiSubscriptionBalance,
                    ],
                ]);

                CreditLedger::create([
                    'customer_id' => $customer->id,
                    'wallet_type' => CreditWallet::TYPE_API,
                    'type' => 'plan_reset',
                    'source_type' => 'subscription_refill',
                    'source_id' => (string) $order->id,
                    'direction' => $apiDelta < 0 ? 'debit' : 'credit',
                    'amount' => abs($apiDelta),
                    'bucket' => 'combined',
                    'credits_delta' => $apiDelta,
                    'balance_before' => $oldApiCombined,
                    'balance_after' => $newApiCombined,
                    'subscription_balance_after' => $newApiSubscriptionBalance,
                    'addon_balance_after' => $oldApiAddon,
                    'related_type' => CreditOrder::class,
                    'related_id' => (string) $order->id,
                    'reference_code' => 'PLAN-API-'.now()->format('YmdHis').'-'.random_int(1000, 9999),
                    'meta' => [
                        'plan_code' => $plan->code,
                        'plan_id' => $plan->id,
                        'previous_plan_id' => $previousPlanId,
                        'grant_id' => $grant->id,
                        'order_id' => $order->id,
                        'billing_cycle' => $billingCycle,
                        'app_granted_credits' => $newSubscriptionBalance,
                        'api_granted_credits' => $newApiSubscriptionBalance,
                    ],
                ]);
            }

            $customer->syncResolvedServicePlan($newSub);

            return $newSub;
        }, 3);
    }

    public function switchStoragePlan(Customer $customer, int $storagePlanId, array $meta = []): CustomerStorageSubscription
    {
        return DB::transaction(function () use ($customer, $storagePlanId, $meta) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $plan = StoragePlan::where('is_active', true)->findOrFail($storagePlanId);
            $billingCycle = $this->normalizeBillingCycle((string) ($meta['billing_cycle'] ?? 'monthly'), ['monthly', 'yearly', 'hourly']);
            $catalogAmountIqd = $plan->priceIqdAmount();
            $originalAmountIqd = (int) ($meta['original_amount_iqd'] ?? $catalogAmountIqd);
            $amountIqd = (int) ($meta['base_amount_iqd'] ?? $meta['discounted_amount_iqd'] ?? $catalogAmountIqd);
            $discountAmountIqd = (int) ($meta['discount_amount_iqd'] ?? max(0, $originalAmountIqd - $amountIqd));
            $couponId = $meta['coupon_id'] ?? null;
            $couponCode = $meta['coupon_code'] ?? null;
            $currencySnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd($amountIqd, $customer, $meta);
            $provider = (string) ($meta['provider'] ?? 'fake');
            $providerRef = (string) ($meta['provider_ref'] ?? ('FAKE-STORAGE-'.now()->format('YmdHis').'-'.random_int(1000, 9999)));
            $paymentMethod = (string) ($meta['payment_method'] ?? $provider);
            $paymentIntentId = $meta['payment_intent_id'] ?? null;
            $paymentId = $meta['payment_id'] ?? null;
            $merchantTransactionId = $meta['merchant_transaction_id'] ?? null;
            $providerTransactionId = $meta['provider_transaction_id'] ?? null;
            $grossAmount = (int) ($meta['gross_amount_iqd'] ?? $amountIqd);
            $surchargeAmount = (int) ($meta['surcharge_amount_iqd'] ?? 0);
            $providerFeeAmount = (int) ($meta['provider_fee_amount_iqd'] ?? 0);
            $netAmount = (int) ($meta['net_amount_iqd'] ?? max(0, $grossAmount - $providerFeeAmount));
            $customerPaymentMethodId = $meta['customer_payment_method_id'] ?? null;
            $renewalStrategy = (string) ($meta['renewal_strategy'] ?? PaymentRecurringStrategy::MANUAL_RENEWAL->value);
            $periodEndsAt = $renewalStrategy === PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                && empty($meta['active_until']) ? null : $this->periodEnd($meta['active_until'] ?? null, $billingCycle);

            $order = CreditOrder::create([
                'customer_id' => $customer->id,
                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'payment_intent_id' => $paymentIntentId,
                'payment_id' => $paymentId,
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
                'original_amount_iqd' => $originalAmountIqd,
                'discount_amount_iqd' => $discountAmountIqd,
                'discounted_amount_iqd' => $amountIqd,
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
                    'coupon' => $meta['coupon'] ?? null,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                ], $meta),
            ]);

            $current = CustomerStorageSubscription::where('customer_id', $customer->id)->latest('id')->lockForUpdate()->first();

            if ($current) {
                $current->update([
                    'status' => 'ended',
                    'ends_at' => now(),
                ]);
            }

            $usedBytes = (int) $customer->storageUsedBytes();
            $quotaBytes = (int) $plan->quota_mb * 1024 * 1024;
            $overQuota = $usedBytes > $quotaBytes;

            $subscription = CustomerStorageSubscription::create([
                'customer_id' => $customer->id,
                'payment_id' => $paymentId,
                'coupon_id' => $couponId,
                'storage_plan_id' => $plan->id,
                'status' => 'active',
                'price_iqd_snapshot' => $amountIqd,
                'original_price_iqd_snapshot' => $originalAmountIqd,
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
                'cycle_ends_on' => $periodEndsAt?->toDateString(),
                'next_renewal_on' => $periodEndsAt?->toDateString(),
                'auto_renew' => $renewalStrategy === PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                    || ($customerPaymentMethodId !== null && $renewalStrategy !== PaymentRecurringStrategy::MANUAL_RENEWAL->value),
                'customer_payment_method_id' => $customerPaymentMethodId,
                'renewal_strategy' => $renewalStrategy,
                'discount_cycles_consumed' => $couponId !== null ? 1 : 0,
                'meta' => [
                    'order_id' => $order->id,
                    'coupon' => $meta['coupon'] ?? null,
                    'provider' => $provider,
                    'over_quota' => $overQuota,
                    'used_bytes' => $usedBytes,
                    'quota_bytes' => $quotaBytes,
                    'billing_cycle' => $billingCycle,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                    'merchant_transaction_id' => $merchantTransactionId,
                    'provider_transaction_id' => $providerTransactionId,
                    'provider_active_until' => $periodEndsAt?->toIso8601String(),
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                ],
            ]);

            $customer->syncResolvedStoragePlan($subscription);

            return $subscription;
        }, 3);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    protected function normalizeBillingCycle(string $billingCycle, array $allowed = ['monthly', 'yearly', 'hourly']): string
    {
        $billingCycle = strtolower(trim($billingCycle));

        return in_array($billingCycle, $allowed, true) ? $billingCycle : 'monthly';
    }

    protected function periodEnd(mixed $value, string $billingCycle = 'monthly'): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            try {
                return Carbon::parse((string) $value);
            } catch (\Throwable) {
            }
        }

        return match ($billingCycle) {
            'yearly' => Carbon::now()->addYear(),
            'hourly' => Carbon::now()->addHour(),
            default => Carbon::now()->addMonth(),
        };
    }

    protected function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

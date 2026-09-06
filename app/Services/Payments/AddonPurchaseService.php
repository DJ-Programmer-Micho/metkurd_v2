<?php

namespace App\Services\Payments;

use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\CreditService;
use Illuminate\Support\Facades\DB;

class AddonPurchaseService
{
    public function __construct(
        protected CheckoutAuthorizationService $authorization,
    ) {}

    public function purchase(Customer $customer, int $productId, array $meta = []): CreditOrder
    {
        $this->authorization->assertAddonPurchaseAllowed($customer);

        return DB::transaction(function () use ($customer, $productId, $meta) {
            $product = CreditProduct::query()
                ->where('is_active', true)
                ->findOrFail($productId);

            $catalogAmountIqd = $product->priceIqdAmount();
            $originalAmountIqd = (int) ($meta['original_amount_iqd'] ?? $catalogAmountIqd);
            $baseAmountIqd = (int) ($meta['base_amount_iqd'] ?? $meta['discounted_amount_iqd'] ?? $catalogAmountIqd);
            $discountAmountIqd = (int) ($meta['discount_amount_iqd'] ?? max(0, $originalAmountIqd - $baseAmountIqd));
            $currencySnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd($baseAmountIqd, $customer);

            $provider = (string) ($meta['provider'] ?? 'fake');
            $providerRef = (string) ($meta['provider_ref'] ?? ('FAKE-ADDON-'.now()->format('YmdHis').'-'.random_int(1000, 9999)));
            $grossAmount = (int) ($meta['gross_amount_iqd'] ?? $baseAmountIqd);
            $surchargeAmount = (int) ($meta['surcharge_amount_iqd'] ?? 0);
            $providerFeeAmount = (int) ($meta['provider_fee_amount_iqd'] ?? 0);
            $netAmount = (int) ($meta['net_amount_iqd'] ?? max(0, $grossAmount - $providerFeeAmount));

            $order = CreditOrder::create([
                'customer_id' => (int) $customer->id,
                'credit_product_id' => (int) $product->id,
                'coupon_id' => $meta['coupon_id'] ?? null,
                'coupon_code' => $meta['coupon_code'] ?? null,
                'payment_intent_id' => $meta['payment_intent_id'] ?? null,
                'payment_id' => $meta['payment_id'] ?? null,
                'order_type' => 'addon',
                'source_type' => 'credit_product',
                'status' => 'paid',
                'credits_amount' => (int) $product->credits_amount,
                'amount_usd' => $currencySnapshot['usd_reference_amount'],
                'currency' => 'IQD',
                'base_currency_code' => 'IQD',
                'base_amount_iqd' => $baseAmountIqd,
                'original_amount_iqd' => $originalAmountIqd,
                'discount_amount_iqd' => $discountAmountIqd,
                'discounted_amount_iqd' => $baseAmountIqd,
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
                'payment_method' => $meta['payment_method'] ?? $provider,
                'provider_ref' => $providerRef,
                'merchant_transaction_id' => $meta['merchant_transaction_id'] ?? null,
                'provider_transaction_id' => $meta['provider_transaction_id'] ?? null,
                'paid_at' => $meta['paid_at'] ?? now(),
                'meta' => array_merge([
                    'ui' => 'addon-credits-page',
                    'product_code' => (string) $product->code,
                    'product_name' => (string) $product->name,
                    'coupon' => $meta['coupon'] ?? null,
                    'display_label' => $currencySnapshot['display_label'],
                    'base_label' => $currencySnapshot['base_label'],
                    'iqd_label' => $currencySnapshot['iqd_label'],
                    'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                    'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                    'fee_breakdown' => $meta['fee_breakdown'] ?? null,
                ], $meta),
            ]);

            app(CreditService::class)->addAddonCredits(
                customerId: (int) $customer->id,
                credits: (int) $product->credits_amount,
                meta: [
                    'related_type' => CreditOrder::class,
                    'related_id' => (string) $order->id,
                    'reference_code' => 'ADDON-'.$order->id,
                    'product_id' => (int) $product->id,
                    'product_code' => (string) $product->code,
                    'product_name' => (string) $product->name,
                    'base_amount_iqd' => (int) $baseAmountIqd,
                    'original_amount_iqd' => (int) $originalAmountIqd,
                    'discount_amount_iqd' => (int) $discountAmountIqd,
                    'ui' => 'addon-credits-page',
                ]
            );

            return $order;
        }, 3);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\PaymentMethod;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use Illuminate\Database\Seeder;

class BillingMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureCurrencies();
        $this->call(BillingCurrencyBootstrapSeeder::class);
        $this->ensureServicePlans();
        $this->ensureStoragePlans();
        $this->ensurePaymentMethods();
    }

    protected function ensureCurrencies(): void
    {
        $rows = [
            ['code' => 'IQD', 'name' => 'Iraqi Dinar', 'symbol' => 'IQD', 'decimal_places' => 0, 'rounding_step' => 250, 'rounding_mode' => 'nearest', 'is_base' => true, 'is_active' => true, 'locale_hint' => 'ar_IQ'],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_US'],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'de_DE'],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_GB'],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_CA'],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_AU'],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'de_CH'],
        ];

        foreach ($rows as $row) {
            Currency::query()->firstOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }

    protected function ensureServicePlans(): void
    {
        $currency = app(BillingCurrencyService::class);

        $rows = [
            [
                'code' => 'free',
                'name' => 'Free',
                'billing_interval' => 'monthly',
                'monthly_credits' => 10000,
                'concurrent_jobs_limit' => 2,
                'is_free' => true,
                'is_active' => true,
                'sort_order' => 1,
                'price_usd_monthly' => 0,
                'price_usd_yearly' => 0,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(0),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(0),
            ],
            [
                'code' => 'student',
                'name' => 'Student',
                'billing_interval' => 'monthly',
                'monthly_credits' => 50000,
                'concurrent_jobs_limit' => 3,
                'is_free' => false,
                'is_active' => true,
                'sort_order' => 2,
                'price_usd_monthly' => 10,
                'price_usd_yearly' => 96,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(10),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(96),
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'billing_interval' => 'monthly',
                'monthly_credits' => 100000,
                'concurrent_jobs_limit' => 4,
                'is_free' => false,
                'is_active' => true,
                'sort_order' => 3,
                'price_usd_monthly' => 20,
                'price_usd_yearly' => 192,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(20),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(192),
            ],
            [
                'code' => 'premium',
                'name' => 'Premium',
                'billing_interval' => 'monthly',
                'monthly_credits' => 250000,
                'concurrent_jobs_limit' => 5,
                'is_free' => false,
                'is_active' => true,
                'sort_order' => 4,
                'price_usd_monthly' => 50,
                'price_usd_yearly' => 480,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(50),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(480),
            ],
        ];

        foreach ($rows as $row) {
            ServicePlan::query()->firstOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }

    protected function ensureStoragePlans(): void
    {
        $rows = [
            ['code' => 'free-512', 'name' => 'Free (512MB)', 'quota_mb' => 512, 'price_usd' => 0, 'price_iqd' => 0, 'is_active' => true, 'sort_order' => 1],
            ['code' => 'student-3072', 'name' => 'Student (3GB)', 'quota_mb' => 3072, 'price_usd' => 0, 'price_iqd' => 0, 'is_active' => true, 'sort_order' => 2],
            ['code' => 'pro-5120', 'name' => 'Pro (5GB)', 'quota_mb' => 5120, 'price_usd' => 0, 'price_iqd' => 0, 'is_active' => true, 'sort_order' => 3],
            ['code' => 'premium-10240', 'name' => 'Premium (10GB)', 'quota_mb' => 10240, 'price_usd' => 0, 'price_iqd' => 0, 'is_active' => true, 'sort_order' => 4],
        ];

        foreach ($rows as $row) {
            StoragePlan::query()->firstOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }

    protected function ensurePaymentMethods(): void
    {
        $isLocalLike = app()->environment(['local', 'testing']);

        $rows = [
            [
                'code' => 'fib',
                'driver' => 'fib',
                'name' => 'First Iraqi Bank',
                'description' => 'FIB checkout for Iraqi customers using QR code or FIB app payment flows.',
                'icon' => 'ri-bank-card-line',
                'is_active' => (bool) config('payments.providers.fib.enabled', false),
                'is_visible' => true,
                'sort_order' => 10,
                'supported_currencies' => ['IQD'],
                'supported_purchase_types' => ['service_plan', 'storage_plan', 'credit_product'],
                'supports_recurring' => true,
                'supports_refunds' => false,
                'supports_webhooks' => true,
                'supports_redirect' => true,
                'supports_qr' => true,
                'settings' => [
                    'credential_source' => 'config.fib',
                    'checkout_mode' => 'qr_or_redirect',
                ],
                'fee_config' => [
                    'default' => [
                        'percent' => 1.0,
                        'fixed_iqd' => 0,
                        'pass_to_customer' => true,
                    ],
                ],
                'meta' => [
                    'adapter_status' => 'ready',
                ],
            ],
            [
                'code' => 'areeba',
                'driver' => 'areeba',
                'name' => 'Areeba Cards',
                'description' => 'Areeba redirect checkout for Visa and MasterCard payments.',
                'icon' => 'ri-visa-line',
                'is_active' => (bool) config('payments.providers.areeba.enabled', false),
                'is_visible' => true,
                'sort_order' => 20,
                'supported_currencies' => ['IQD'],
                'supported_purchase_types' => ['service_plan', 'storage_plan', 'credit_product'],
                'supports_recurring' => (bool) config('payments.providers.areeba.supports.recurring', false),
                'supports_refunds' => true,
                'supports_webhooks' => true,
                'supports_redirect' => true,
                'supports_qr' => false,
                'settings' => [
                    'credential_source' => 'config.payments.providers.areeba',
                    'checkout_mode' => 'redirect',
                ],
                'fee_config' => [
                    'local' => [
                        'percent' => 1.0,
                        'fixed_iqd' => 264,
                        'pass_to_customer' => false,
                    ],
                    'international' => [
                        'percent' => 3.25,
                        'fixed_iqd' => 396,
                        'pass_to_customer' => false,
                    ],
                    'unknown' => [
                        'percent' => 3.25,
                        'fixed_iqd' => 396,
                        'pass_to_customer' => false,
                    ],
                ],
                'meta' => [
                    'adapter_status' => 'planned',
                ],
            ],
            [
                'code' => 'fake',
                'driver' => 'fake',
                'name' => 'Fake Payments',
                'description' => 'Instant developer/test payment flow used before live provider adapters are enabled.',
                'icon' => 'ri-flask-line',
                'is_active' => (bool) config('payments.providers.fake.enabled', true),
                'is_visible' => $isLocalLike,
                'sort_order' => 999,
                'supported_currencies' => ['IQD'],
                'supported_purchase_types' => ['service_plan', 'storage_plan', 'credit_product'],
                'supports_recurring' => false,
                'supports_refunds' => false,
                'supports_webhooks' => false,
                'supports_redirect' => false,
                'supports_qr' => false,
                'settings' => [
                    'credential_source' => 'config.payments.providers.fake',
                    'checkout_mode' => 'instant',
                ],
                'fee_config' => [
                    'default' => [
                        'percent' => 0,
                        'fixed_iqd' => 0,
                        'pass_to_customer' => false,
                    ],
                ],
                'meta' => [
                    'adapter_status' => 'ready',
                ],
            ],
        ];

        foreach ($rows as $row) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }
}

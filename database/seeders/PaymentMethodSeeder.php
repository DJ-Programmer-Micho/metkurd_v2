<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $isLocalLike = app()->environment(['local', 'testing']);

        $methods = [
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
                'supports_recurring' => false,
                'supports_refunds' => false,
                'supports_webhooks' => true,
                'supports_redirect' => true,
                'supports_qr' => true,
                'settings' => [
                    'credential_source' => 'config.services.fib',
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
                    'admin_note' => 'Runtime credentials live in config/services.php and .env. Database values here are non-secret display and business rules only.',
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
                    'admin_note' => 'Credentials stay in .env/config. Database values here are non-secret display and business rules only.',
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
                    'admin_note' => 'Keep hidden or disabled outside local/test environments.',
                ],
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                $method,
            );
        }
    }
}

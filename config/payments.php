<?php

use App\Enums\PaymentCardOrigin;
use App\Enums\PaymentProvider;

$envBool = static function (string $key, bool $default = false): bool {
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (bool) $value;
    }

    $parsed = filter_var((string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    return $parsed ?? $default;
};

return [
    'default_provider' => env('PAYMENTS_DEFAULT_PROVIDER', PaymentProvider::FIB->value),
    'fake_enabled' => $envBool('PAYMENTS_FAKE_ENABLED', true),

    'providers' => [
        PaymentProvider::FAKE->value => [
            'label' => 'Fake Payments',
            'provider_class' => \App\Services\Payments\Providers\FakePaymentProvider::class,
            'enabled' => $envBool('PAYMENTS_FAKE_ENABLED', true),
            'supports' => [
                'one_time' => true,
                'recurring' => false,
                'refund' => false,
                'cancel' => false,
            ],
        ],

        PaymentProvider::FIB->value => [
            'label' => 'First Iraqi Bank',
            'provider_class' => \App\Services\Payments\Providers\FibPaymentProvider::class,
            'enabled' => $envBool('FIB_ENABLED', false),
            'supports' => [
                'one_time' => true,
                'recurring' => true,
                'refund' => true,
                'cancel' => true,
            ],
            'fees' => [
                'percent' => 1.0,
                'fixed_iqd' => 0,
                'pass_to_customer' => true,
            ],
        ],

        PaymentProvider::AREEBA->value => [
            'label' => 'Areeba Cards',
            'provider_class' => \App\Services\Payments\Providers\AreebaPaymentProvider::class,
            'enabled' => $envBool('AREEBA_ENABLED', false),
            'supports' => [
                'one_time' => true,
                'recurring' => $envBool('AREEBA_SCHEDULE_ENABLED', false),
                'refund' => true,
                'cancel' => false,
            ],
            'fees' => [
                PaymentCardOrigin::LOCAL->value => [
                    'percent' => 1.0,
                    'fixed_iqd' => 264,
                    'pass_to_customer' => false,
                ],
                PaymentCardOrigin::INTERNATIONAL->value => [
                    'percent' => 3.25,
                    'fixed_iqd' => 396,
                    'pass_to_customer' => false,
                ],
                PaymentCardOrigin::UNKNOWN->value => [
                    'percent' => 3.25,
                    'fixed_iqd' => 396,
                    'pass_to_customer' => false,
                ],
            ],
        ],
    ],
];

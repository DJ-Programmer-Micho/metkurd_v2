<?php

namespace App\Services\Payments;

use App\Enums\PaymentCardOrigin;
use App\Models\PaymentMethod;

class PaymentFeeCalculator
{
    public function __construct(
        protected PaymentMethodCatalog $methods,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(PaymentMethod|string $paymentMethod, int $baseAmountIqd, ?string $cardOrigin = null): array
    {
        $method = $paymentMethod instanceof PaymentMethod
            ? $paymentMethod
            : $this->methods->find((string) $paymentMethod);

        $provider = strtolower(trim((string) ($method?->driver ?? $paymentMethod)));
        $cardOrigin = strtolower(trim((string) ($cardOrigin ?: PaymentCardOrigin::UNKNOWN->value)));
        $baseAmountIqd = max(0, (int) round((float) $baseAmountIqd));

        $rule = $this->providerRule($method, $provider, $cardOrigin);
        $percent = (float) ($rule['percent'] ?? 0);
        $fixed = (int) round((float) ($rule['fixed_iqd'] ?? 0));
        $passToCustomer = (bool) ($rule['pass_to_customer'] ?? false);

        $providerFee = (int) ceil(($baseAmountIqd * ($percent / 100)) + $fixed);
        $surcharge = $passToCustomer ? $providerFee : 0;
        $grossAmount = $baseAmountIqd + $surcharge;
        $netAmount = max(0, $grossAmount - $providerFee);

        return [
            'base_amount_iqd' => $baseAmountIqd,
            'gross_amount_iqd' => $grossAmount,
            'surcharge_amount_iqd' => $surcharge,
            'provider_fee_amount_iqd' => $providerFee,
            'net_amount_iqd' => $netAmount,
            'fee_currency_code' => 'IQD',
            'card_origin' => $cardOrigin,
            'fee_breakdown' => [
                'provider' => $provider,
                'card_origin' => $cardOrigin,
                'percent' => $percent,
                'fixed_iqd' => $fixed,
                'pass_to_customer' => $passToCustomer,
            ],
        ];
    }

    protected function providerRule(?PaymentMethod $method, string $provider, string $cardOrigin): array
    {
        $feeConfig = $method?->fee_config;

        if (is_array($feeConfig) && $feeConfig !== []) {
            $specific = $feeConfig[$cardOrigin] ?? null;
            $default = $feeConfig['default'] ?? $feeConfig[PaymentCardOrigin::UNKNOWN->value] ?? null;

            if (is_array($specific)) {
                return $specific;
            }

            if (is_array($default)) {
                return $default;
            }
        }

        return match ($provider) {
            'fib' => config('payments.providers.fib.fees', []),
            'areeba' => config("payments.providers.areeba.fees.{$cardOrigin}")
                ?? config('payments.providers.areeba.fees.' . PaymentCardOrigin::UNKNOWN->value, []),
            default => [
                'percent' => 0,
                'fixed_iqd' => 0,
                'pass_to_customer' => false,
            ],
        };
    }
}

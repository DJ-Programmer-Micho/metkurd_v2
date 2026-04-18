<?php

namespace App\Services\Payments;

use App\Enums\PaymentCardOrigin;
use App\Models\PaymentMethod;
use InvalidArgumentException;

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
        $grossFormulaAmount = null;

        if ($passToCustomer) {
            if ($percent >= 100.0) {
                throw new InvalidArgumentException('Pass-through fee percent must be lower than 100.');
            }

            $grossFormulaAmount = ($baseAmountIqd + $fixed) / (1 - ($percent / 100));
            $grossAmount = max(0, (int) ceil($grossFormulaAmount));
            $providerFee = $this->providerFeeForAmount($grossAmount, $percent, $fixed);
            $netAmount = max(0, $grossAmount - $providerFee);

            while ($netAmount < $baseAmountIqd) {
                $grossAmount++;
                $providerFee = $this->providerFeeForAmount($grossAmount, $percent, $fixed);
                $netAmount = max(0, $grossAmount - $providerFee);
            }

            $surcharge = max(0, $grossAmount - $baseAmountIqd);
        } else {
            $providerFee = $this->providerFeeForAmount($baseAmountIqd, $percent, $fixed);
            $surcharge = 0;
            $grossAmount = $baseAmountIqd;
            $netAmount = max(0, $grossAmount - $providerFee);
        }

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
                'gross_formula_amount_iqd_raw' => $grossFormulaAmount !== null
                    ? round($grossFormulaAmount, 6)
                    : null,
                'rounding_strategy' => $passToCustomer ? 'ceil_to_whole_iqd' : 'none',
            ],
        ];
    }

    protected function providerFeeForAmount(int $amountIqd, float $percent, int $fixed): int
    {
        return (int) ceil(($amountIqd * ($percent / 100)) + $fixed);
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

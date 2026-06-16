<?php

namespace App\Services\Billing;

use App\Models\CountryCurrencyMap;
use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Stevebauman\Location\Facades\Location;

class BillingCurrencyService
{
    public const BASE_CURRENCY = 'IQD';

    public const SECONDARY_CURRENCY = 'USD';

    public const ADMIN_PREVIEW_CURRENCIES = ['IQD', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF'];

    public function baseCurrencyCode(): string
    {
        return self::BASE_CURRENCY;
    }

    public function secondaryCurrencyCode(): string
    {
        return self::SECONDARY_CURRENCY;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveDisplayContext(?Customer $customer = null, array $context = []): array
    {
        $customer?->loadMissing('profile');

        $preferredCurrency = $this->normalizeCurrencyCode(
            $context['display_currency_code']
            ?? $customer?->profile?->display_currency_code
            ?? null
        );

        $countryContext = $this->resolveCountryContext($customer, $context);
        $countryCode = $countryContext['country_code'];

        if ($preferredCurrency !== null && $this->isSupportedCurrency($preferredCurrency)) {
            $currencyCode = $this->resolveCurrencyWithRateFallback($preferredCurrency);

            return [
                'currency_code' => $currencyCode,
                'country_code' => $countryCode,
                'source' => $currencyCode === $preferredCurrency ? 'profile_currency' : 'profile_currency_rate_fallback',
            ];
        }

        $mappedCurrency = $countryCode !== null
            ? $this->currencyCodeForCountry($countryCode)
            : null;

        if ($mappedCurrency !== null) {
            $currencyCode = $this->resolveCurrencyWithRateFallback($mappedCurrency);

            return [
                'currency_code' => $currencyCode,
                'country_code' => $countryCode,
                'source' => $currencyCode === $mappedCurrency
                    ? $countryContext['source']
                    : $countryContext['source'].'_rate_fallback',
            ];
        }

        return [
            'currency_code' => self::BASE_CURRENCY,
            'country_code' => $countryCode,
            'source' => 'default',
        ];
    }

    /**
     * Canonical amount is always IQD.
     *
     * @return array<string, mixed>
     */
    public function priceDataForBaseAmountIqd(int|float|string|null $amountIqd, ?Customer $customer = null, array $context = []): array
    {
        $baseAmountIqd = $this->normalizeBaseAmount($amountIqd);
        $resolved = $this->resolveDisplayContext($customer, $context);
        $converted = $this->convertBaseAmount($baseAmountIqd, (string) $resolved['currency_code']);
        $usdReference = $this->convertBaseAmount($baseAmountIqd, self::SECONDARY_CURRENCY);

        return [
            'base_currency_code' => self::BASE_CURRENCY,
            'base_amount_iqd' => $baseAmountIqd,
            'base_label' => $this->formatAmount($baseAmountIqd, self::BASE_CURRENCY),
            'display_currency_code' => $converted['currency_code'],
            'display_exchange_rate' => $converted['exchange_rate'],
            'display_amount_raw' => $converted['raw_amount'],
            'display_amount_rounded' => $converted['rounded_amount'],
            'display_amount' => $converted['rounded_amount'],
            'display_rounding_step' => $converted['rounding_step'],
            'display_rounding_mode' => $converted['rounding_mode'],
            'display_country_code' => $resolved['country_code'],
            'currency_resolution_source' => $resolved['source'],
            'iqd_label' => $this->formatAmount($baseAmountIqd, self::BASE_CURRENCY),
            'display_label' => $this->formatAmount((float) $converted['rounded_amount'], (string) $converted['currency_code']),
            'estimated_label' => (string) $converted['currency_code'] === self::BASE_CURRENCY
                ? $this->formatAmount($baseAmountIqd, self::BASE_CURRENCY)
                : '~'.$this->formatAmount((float) $converted['rounded_amount'], (string) $converted['currency_code']),
            'has_localized_estimate' => (string) $converted['currency_code'] !== self::BASE_CURRENCY,
            'usd_reference_amount' => $usdReference['rounded_amount'],
            'usd_reference_label' => $this->formatAmount($usdReference['rounded_amount'], 'USD'),
            'usd_label' => $this->formatAmount($usdReference['rounded_amount'], 'USD'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotForBaseAmountIqd(int|float|string|null $amountIqd, ?Customer $customer = null, array $context = []): array
    {
        $priceData = $this->priceDataForBaseAmountIqd($amountIqd, $customer, $context);

        return [
            'base_currency_code' => $priceData['base_currency_code'],
            'base_amount_iqd' => $priceData['base_amount_iqd'],
            'display_currency_code' => $priceData['display_currency_code'],
            'display_exchange_rate' => $priceData['display_exchange_rate'],
            'display_amount_raw' => $priceData['display_amount_raw'],
            'display_amount_rounded' => $priceData['display_amount_rounded'],
            'display_amount' => $priceData['display_amount'],
            'display_rounding_step' => $priceData['display_rounding_step'],
            'display_rounding_mode' => $priceData['display_rounding_mode'],
            'display_country_code' => $priceData['display_country_code'],
            'currency_resolution_source' => $priceData['currency_resolution_source'],
            'display_label' => $priceData['display_label'],
            'base_label' => $priceData['base_label'],
            'iqd_label' => $priceData['iqd_label'],
            'usd_reference_amount' => $priceData['usd_reference_amount'],
            'usd_reference_label' => $priceData['usd_reference_label'],
            'usd_label' => $priceData['usd_label'],
            'has_localized_estimate' => $priceData['has_localized_estimate'],
        ];
    }

    /**
     * Compatibility wrapper while legacy callers are still being migrated.
     *
     * @return array<string, mixed>
     */
    public function priceDataForUsd(int|float|string|null $amountUsd, ?Customer $customer = null, array $context = []): array
    {
        return $this->priceDataForBaseAmountIqd(
            $this->legacyUsdAmountToIqd($amountUsd),
            $customer,
            $context,
        );
    }

    /**
     * Compatibility wrapper while legacy callers are still being migrated.
     *
     * @return array<string, mixed>
     */
    public function snapshotForUsdAmount(int|float|string|null $amountUsd, ?Customer $customer = null, array $context = []): array
    {
        return $this->snapshotForBaseAmountIqd(
            $this->legacyUsdAmountToIqd($amountUsd),
            $customer,
            $context,
        );
    }

    /**
     * Compatibility path while old catalog tables still hold USD.
     */
    public function legacyUsdAmountToIqd(float|int|string|null $amountUsd): int
    {
        $usdAmount = round((float) ($amountUsd ?? 0), 2);

        if ($usdAmount <= 0) {
            return 0;
        }

        $usdPerIqd = $this->currentRate(self::BASE_CURRENCY, self::SECONDARY_CURRENCY);

        if ($usdPerIqd === null || $usdPerIqd <= 0) {
            return 0;
        }

        $rawIqd = $usdAmount / $usdPerIqd;

        return (int) round($this->roundAmount($rawIqd, self::BASE_CURRENCY));
    }

    /**
     * @return array{currency_code:string,exchange_rate:float,raw_amount:float,rounded_amount:float,rounding_step:float,rounding_mode:string}
     */
    public function convertBaseAmount(int|float|string|null $amountIqd, string $targetCurrencyCode): array
    {
        $baseAmountIqd = $this->normalizeBaseAmount($amountIqd);
        $currencyCode = $this->resolveCurrencyWithRateFallback($targetCurrencyCode);
        $exchangeRate = $this->currentRate(self::BASE_CURRENCY, $currencyCode) ?? 1.0;
        $rawAmount = $baseAmountIqd * $exchangeRate;
        $roundedAmount = $this->roundAmount($rawAmount, $currencyCode);
        $currency = $this->currency($currencyCode);

        return [
            'currency_code' => $currencyCode,
            'exchange_rate' => $exchangeRate,
            'raw_amount' => round($rawAmount, 8),
            'rounded_amount' => $roundedAmount,
            'rounding_step' => (float) ($currency?->rounding_step ?? $this->fallbackRoundingStep($currencyCode)),
            'rounding_mode' => (string) ($currency?->rounding_mode ?? 'nearest'),
        ];
    }

    public function roundAmount(float|int|string|null $amount, string $currencyCode): float
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode) ?? self::BASE_CURRENCY;
        $currency = $this->currency($currencyCode);
        $step = max((float) ($currency?->rounding_step ?? $this->fallbackRoundingStep($currencyCode)), 0.0001);
        $mode = strtolower(trim((string) ($currency?->rounding_mode ?? 'nearest')));
        $decimals = (int) ($currency?->decimal_places ?? 2);
        $numericAmount = (float) ($amount ?? 0);
        $scaled = $numericAmount / $step;

        $rounded = match ($mode) {
            'up' => ceil($scaled) * $step,
            'down' => floor($scaled) * $step,
            default => round($scaled) * $step,
        };

        return round($rounded, max($decimals, 4));
    }

    public function formatAmount(float|int|string|null $amount, string $currencyCode): string
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode) ?? self::BASE_CURRENCY;
        $currency = $this->currency($currencyCode);
        $decimals = (int) ($currency?->decimal_places ?? 2);
        $numericAmount = round((float) ($amount ?? 0), $decimals);

        if (class_exists(\NumberFormatter::class)) {
            $locale = (string) ($currency?->locale_hint ?? 'en_US');
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

            if ($formatter !== false) {
                $formatted = $formatter->formatCurrency($numericAmount, $currencyCode);

                if (is_string($formatted)) {
                    return $formatted;
                }
            }
        }

        $symbol = (string) ($currency?->symbol ?: $currencyCode);
        $formattedNumber = number_format($numericAmount, $decimals);

        return in_array($currencyCode, ['USD', 'EUR', 'GBP', 'TRY'], true) || str_contains($symbol, '$') || $symbol === '£' || $symbol === '€'
            ? $symbol.$formattedNumber
            : $symbol.' '.$formattedNumber;
    }

    public function formatBaseWithOptionalDisplay(int|float|string|null $amountIqd, ?string $displayCurrencyCode = null): string
    {
        $baseLabel = $this->formatAmount($amountIqd, self::BASE_CURRENCY);
        $displayCurrencyCode = $this->normalizeCurrencyCode($displayCurrencyCode);

        if ($displayCurrencyCode === null || $displayCurrencyCode === self::BASE_CURRENCY) {
            return $baseLabel;
        }

        $converted = $this->convertBaseAmount($amountIqd, $displayCurrencyCode);
        $displayLabel = $this->formatAmount($converted['rounded_amount'], $converted['currency_code']);

        return $baseLabel.' ('.$displayLabel.')';
    }

    public function supportedCurrencyOptions(): array
    {
        return Cache::remember('billing-supported-currencies', now()->addMinutes(10), function () {
            return Currency::query()
                ->where('is_active', true)
                ->orderByDesc('is_base')
                ->orderBy('code')
                ->get(['code', 'name'])
                ->mapWithKeys(fn (Currency $currency) => [$currency->code => "{$currency->code} - {$currency->name}"])
                ->all();
        });
    }

    /**
     * @return array<int, array{code:string,label:string,exchange_rate:float,raw_amount:float,rounded_amount:float,formatted:string,is_base:bool}>
     */
    public function previewMatrixForBaseAmountIqd(int|float|string|null $amountIqd, array $currencyCodes = self::ADMIN_PREVIEW_CURRENCIES): array
    {
        $baseAmountIqd = $this->normalizeBaseAmount($amountIqd);

        return collect($currencyCodes)
            ->map(fn ($code) => $this->normalizeCurrencyCode((string) $code))
            ->filter()
            ->unique()
            ->filter(fn (string $code) => $code === self::BASE_CURRENCY || $this->isSupportedCurrency($code))
            ->map(function (string $currencyCode) use ($baseAmountIqd) {
                if ($currencyCode === self::BASE_CURRENCY) {
                    return [
                        'code' => self::BASE_CURRENCY,
                        'label' => $this->formatAmount($baseAmountIqd, self::BASE_CURRENCY),
                        'exchange_rate' => 1.0,
                        'raw_amount' => (float) $baseAmountIqd,
                        'rounded_amount' => (float) $baseAmountIqd,
                        'formatted' => $this->formatAmount($baseAmountIqd, self::BASE_CURRENCY),
                        'is_base' => true,
                    ];
                }

                $converted = $this->convertBaseAmount($baseAmountIqd, $currencyCode);

                return [
                    'code' => (string) $converted['currency_code'],
                    'label' => $this->formatAmount((float) $converted['rounded_amount'], (string) $converted['currency_code']),
                    'exchange_rate' => (float) $converted['exchange_rate'],
                    'raw_amount' => (float) $converted['raw_amount'],
                    'rounded_amount' => (float) $converted['rounded_amount'],
                    'formatted' => $this->formatAmount((float) $converted['rounded_amount'], (string) $converted['currency_code']),
                    'is_base' => false,
                ];
            })
            ->values()
            ->all();
    }

    public function managedBaseCurrencyForQuote(string $quoteCurrencyCode): string
    {
        $quoteCurrencyCode = $this->normalizeCurrencyCode($quoteCurrencyCode) ?? self::BASE_CURRENCY;

        if ($quoteCurrencyCode === self::BASE_CURRENCY || $quoteCurrencyCode === self::SECONDARY_CURRENCY) {
            return self::BASE_CURRENCY;
        }

        return self::SECONDARY_CURRENCY;
    }

    public function currentManagedRateForQuote(string $quoteCurrencyCode): ?float
    {
        $quoteCurrencyCode = $this->normalizeCurrencyCode($quoteCurrencyCode) ?? self::BASE_CURRENCY;

        if ($quoteCurrencyCode === self::BASE_CURRENCY) {
            return 1.0;
        }

        return $this->currentStoredRate(
            $this->managedBaseCurrencyForQuote($quoteCurrencyCode),
            $quoteCurrencyCode
        );
    }

    public function currentDerivedRateForQuote(string $quoteCurrencyCode): ?float
    {
        $quoteCurrencyCode = $this->normalizeCurrencyCode($quoteCurrencyCode) ?? self::BASE_CURRENCY;

        return $this->currentRate(self::BASE_CURRENCY, $quoteCurrencyCode);
    }

    protected function resolveCurrencyWithRateFallback(string $currencyCode): string
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode) ?? self::BASE_CURRENCY;

        if ($currencyCode === self::BASE_CURRENCY) {
            return self::BASE_CURRENCY;
        }

        return $this->currentRate(self::BASE_CURRENCY, $currencyCode) !== null
            ? $currencyCode
            : self::BASE_CURRENCY;
    }

    /**
     * @return array{country_code:?string,source:string}
     */
    protected function resolveCountryContext(?Customer $customer = null, array $context = []): array
    {
        $explicitCountry = $this->normalizeCountryCode($context['country_code'] ?? null);

        if ($explicitCountry !== null) {
            return [
                'country_code' => $explicitCountry,
                'source' => 'explicit_country',
            ];
        }

        $profileCountry = $this->normalizeCountryCode($customer?->profile?->country ?? null);

        if ($profileCountry !== null) {
            return [
                'country_code' => $profileCountry,
                'source' => 'profile_country',
            ];
        }

        $ip = trim((string) ($context['ip'] ?? request()->ip() ?? ''));
        $ipCountry = $this->resolveCountryFromIp($ip);

        if ($ipCountry !== null) {
            return [
                'country_code' => $ipCountry,
                'source' => 'ip_country',
            ];
        }

        return [
            'country_code' => null,
            'source' => 'default',
        ];
    }

    protected function resolveCountryFromIp(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }

        $isPublicIp = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublicIp === false) {
            return null;
        }

        return Cache::remember("billing-country-from-ip:{$ip}", now()->addDay(), function () use ($ip) {
            try {
                $location = Location::get($ip);
                $countryCode = $this->normalizeCountryCode(data_get($location, 'countryCode'));

                return $countryCode ?: null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    protected function currency(string $currencyCode): ?Currency
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode);

        if ($currencyCode === null) {
            return null;
        }

        return Cache::remember("billing-currency:{$currencyCode}", now()->addMinutes(10), function () use ($currencyCode) {
            return Currency::query()
                ->where('code', $currencyCode)
                ->where('is_active', true)
                ->first();
        });
    }

    protected function isSupportedCurrency(string $currencyCode): bool
    {
        return $this->currency($currencyCode) !== null;
    }

    protected function currencyCodeForCountry(string $countryCode): ?string
    {
        $countryCode = $this->normalizeCountryCode($countryCode);

        if ($countryCode === null) {
            return null;
        }

        return Cache::remember("billing-country-currency:{$countryCode}", now()->addMinutes(30), function () use ($countryCode) {
            return CountryCurrencyMap::query()
                ->where('country_code', $countryCode)
                ->where('is_active', true)
                ->value('currency_code');
        });
    }

    protected function currentRate(string $baseCurrencyCode, string $quoteCurrencyCode): ?float
    {
        $baseCurrencyCode = $this->normalizeCurrencyCode($baseCurrencyCode) ?? self::BASE_CURRENCY;
        $quoteCurrencyCode = $this->normalizeCurrencyCode($quoteCurrencyCode) ?? self::BASE_CURRENCY;

        if ($baseCurrencyCode === $quoteCurrencyCode) {
            return 1.0;
        }

        return Cache::remember(
            "billing-rate:{$baseCurrencyCode}:{$quoteCurrencyCode}",
            now()->addMinutes(10),
            function () use ($baseCurrencyCode, $quoteCurrencyCode) {
                $directRate = $this->currentStoredRate($baseCurrencyCode, $quoteCurrencyCode);

                if ($this->isManagedPair($baseCurrencyCode, $quoteCurrencyCode)) {
                    return $directRate;
                }

                if ($baseCurrencyCode === self::BASE_CURRENCY && $quoteCurrencyCode !== self::BASE_CURRENCY) {
                    $iqdToUsd = $this->currentStoredRate(self::BASE_CURRENCY, self::SECONDARY_CURRENCY);

                    if ($quoteCurrencyCode === self::SECONDARY_CURRENCY) {
                        return $iqdToUsd;
                    }

                    $usdToQuote = $this->currentStoredRate(self::SECONDARY_CURRENCY, $quoteCurrencyCode);

                    if ($iqdToUsd !== null && $usdToQuote !== null) {
                        return round($iqdToUsd * $usdToQuote, 12);
                    }

                    if ($directRate !== null) {
                        return $directRate;
                    }
                }

                $baseToUsd = $this->resolveRateToUsd($baseCurrencyCode);
                $usdToQuote = $this->resolveRateFromUsd($quoteCurrencyCode);

                if ($baseToUsd !== null && $usdToQuote !== null) {
                    return round($baseToUsd * $usdToQuote, 12);
                }

                return $directRate;
            }
        );
    }

    protected function isManagedPair(string $baseCurrencyCode, string $quoteCurrencyCode): bool
    {
        if ($baseCurrencyCode === self::BASE_CURRENCY && $quoteCurrencyCode === self::SECONDARY_CURRENCY) {
            return true;
        }

        return $baseCurrencyCode === self::SECONDARY_CURRENCY
            && ! in_array($quoteCurrencyCode, [self::SECONDARY_CURRENCY, self::BASE_CURRENCY], true);
    }

    protected function currentStoredRate(string $baseCurrencyCode, string $quoteCurrencyCode): ?float
    {
        $baseCurrencyCode = $this->normalizeCurrencyCode($baseCurrencyCode) ?? self::BASE_CURRENCY;
        $quoteCurrencyCode = $this->normalizeCurrencyCode($quoteCurrencyCode) ?? self::BASE_CURRENCY;

        if ($baseCurrencyCode === $quoteCurrencyCode) {
            return 1.0;
        }

        $rate = CurrencyExchangeRate::query()
            ->where('base_currency_code', $baseCurrencyCode)
            ->where('quote_currency_code', $quoteCurrencyCode)
            ->where('is_current', true)
            ->orderByDesc('effective_at')
            ->value('rate');

        return $rate !== null ? (float) $rate : null;
    }

    protected function resolveRateToUsd(string $baseCurrencyCode): ?float
    {
        if ($baseCurrencyCode === self::SECONDARY_CURRENCY) {
            return 1.0;
        }

        if ($baseCurrencyCode === self::BASE_CURRENCY) {
            return $this->currentStoredRate(self::BASE_CURRENCY, self::SECONDARY_CURRENCY);
        }

        $usdToBase = $this->currentStoredRate(self::SECONDARY_CURRENCY, $baseCurrencyCode);

        if ($usdToBase === null || $usdToBase <= 0) {
            return null;
        }

        return 1 / $usdToBase;
    }

    protected function resolveRateFromUsd(string $quoteCurrencyCode): ?float
    {
        if ($quoteCurrencyCode === self::SECONDARY_CURRENCY) {
            return 1.0;
        }

        if ($quoteCurrencyCode === self::BASE_CURRENCY) {
            $iqdToUsd = $this->currentStoredRate(self::BASE_CURRENCY, self::SECONDARY_CURRENCY);

            if ($iqdToUsd === null || $iqdToUsd <= 0) {
                return null;
            }

            return 1 / $iqdToUsd;
        }

        return $this->currentStoredRate(self::SECONDARY_CURRENCY, $quoteCurrencyCode);
    }

    protected function fallbackRoundingStep(string $currencyCode): float
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode) ?? self::BASE_CURRENCY;

        return match ($currencyCode) {
            'IQD' => 250,
            'IRR' => 1000,
            default => 0.01,
        };
    }

    protected function normalizeBaseAmount(int|float|string|null $amountIqd): int
    {
        return max(0, (int) round((float) ($amountIqd ?? 0)));
    }

    protected function normalizeCountryCode(?string $countryCode): ?string
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        return $countryCode !== '' ? $countryCode : null;
    }

    protected function normalizeCurrencyCode(?string $currencyCode): ?string
    {
        $currencyCode = strtoupper(trim((string) $currencyCode));

        return $currencyCode !== '' ? $currencyCode : null;
    }
}

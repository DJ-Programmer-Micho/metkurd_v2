<?php

namespace App\Support\Admin;

use App\Services\Billing\BillingCurrencyService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

trait InteractsWithPaymentAdmin
{
    #[Url(as: 'currency', keep: true)]
    public string $displayCurrencyCode = 'IQD';

    protected function decodeJsonTextarea(?string $value, string $field): ?array
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => __('Enter valid JSON data.'),
            ]);
        }

        return $decoded;
    }

    protected function encodeJsonTextarea($value): string
    {
        if (!$value) {
            return '';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    #[Computed]
    public function displayCurrencyOptions(): array
    {
        return app(BillingCurrencyService::class)->supportedCurrencyOptions();
    }

    public function updatedDisplayCurrencyCode(string $value): void
    {
        $value = strtoupper(trim($value));

        if (! array_key_exists($value, $this->displayCurrencyOptions)) {
            $this->displayCurrencyCode = 'IQD';
        }
    }

    public function canonicalCurrencyCode(): string
    {
        return app(BillingCurrencyService::class)->baseCurrencyCode();
    }

    public function formatMoney($value, ?string $currencyCode = null): string
    {
        return app(BillingCurrencyService::class)->formatAmount(
            (float) ($value ?? 0),
            $currencyCode ?: $this->canonicalCurrencyCode()
        );
    }

    public function formatCanonicalMoneyWithOptionalDisplay($value): string
    {
        return app(BillingCurrencyService::class)->formatBaseWithOptionalDisplay(
            (int) round((float) ($value ?? 0)),
            $this->displayCurrencyCode
        );
    }

    public function formatCanonicalPrimary($value): string
    {
        return $this->formatMoney($value, $this->canonicalCurrencyCode());
    }

    public function formatOptionalDisplayMoney($value): ?string
    {
        $displayCurrencyCode = strtoupper(trim((string) $this->displayCurrencyCode));

        if ($displayCurrencyCode === '' || $displayCurrencyCode === $this->canonicalCurrencyCode()) {
            return null;
        }

        $converted = app(BillingCurrencyService::class)->convertBaseAmount(
            (int) round((float) ($value ?? 0)),
            $displayCurrencyCode
        );

        return app(BillingCurrencyService::class)->formatAmount(
            (float) $converted['rounded_amount'],
            (string) $converted['currency_code']
        );
    }

    public function usdReferenceAmount($value): float
    {
        $converted = app(BillingCurrencyService::class)->convertBaseAmount(
            (int) round((float) ($value ?? 0)),
            'USD'
        );

        return (string) $converted['currency_code'] === 'USD'
            ? (float) $converted['rounded_amount']
            : 0.0;
    }

    public function previewCurrencyCodes(): array
    {
        $preferred = BillingCurrencyService::ADMIN_PREVIEW_CURRENCIES;
        $supported = $this->displayCurrencyOptions;

        return collect($preferred)
            ->filter(fn (string $code) => array_key_exists($code, $supported))
            ->values()
            ->all();
    }

    public function pricePreviewRows($value): array
    {
        return app(BillingCurrencyService::class)->previewMatrixForBaseAmountIqd(
            (int) round((float) ($value ?? 0)),
            $this->previewCurrencyCodes()
        );
    }

    protected function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];

        $key = $table . '.' . $column;

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = Schema::hasColumn($table, $column);
        }

        return $cache[$key];
    }

    protected function qualifiedColumn(string $table, string $column): string
    {
        return $table . '.' . $column;
    }

    protected function canonicalAmountSql(string $table, string $baseAmountColumn = 'base_amount_iqd', string $legacyUsdColumn = 'amount_usd'): string
    {
        $baseQualified = $this->qualifiedColumn($table, $baseAmountColumn);
        $legacyQualified = $this->qualifiedColumn($table, $legacyUsdColumn);
        $hasBaseColumn = $this->tableHasColumn($table, $baseAmountColumn);
        $hasLegacyColumn = $this->tableHasColumn($table, $legacyUsdColumn);

        $usdRate = app(BillingCurrencyService::class)->convertBaseAmount(1, 'USD');
        $usdPerIqd = (string) ($usdRate['currency_code'] ?? '') === 'USD'
            ? (float) ($usdRate['exchange_rate'] ?? 0)
            : 0.0;

        if ($hasBaseColumn && ! $hasLegacyColumn) {
            return "COALESCE({$baseQualified}, 0)";
        }

        if (! $hasBaseColumn && $hasLegacyColumn) {
            if ($usdPerIqd <= 0) {
                return '0';
            }

            return "ROUND({$legacyQualified} / {$usdPerIqd}, 0)";
        }

        if ($hasBaseColumn && $hasLegacyColumn) {
            if ($usdPerIqd <= 0) {
                return "COALESCE({$baseQualified}, 0)";
            }

            return "COALESCE({$baseQualified}, ROUND({$legacyQualified} / {$usdPerIqd}, 0))";
        }

        return '0';
    }

    protected function effectiveCatalogAmountSql(string $table, string $iqdColumn, string $legacyUsdColumn): string
    {
        return $this->canonicalAmountSql($table, $iqdColumn, $legacyUsdColumn);
    }

    public function formatCredits($value): string
    {
        return number_format((int) round((float) ($value ?? 0)));
    }

    public function formatStorageQuota($quotaMb): string
    {
        $quotaMb = max(0, (int) ($quotaMb ?? 0));

        if ($quotaMb >= 1024) {
            $quotaGb = $quotaMb / 1024;

            return number_format($quotaGb, $quotaGb >= 10 ? 0 : 1) . ' GB';
        }

        return number_format($quotaMb) . ' MB';
    }
}

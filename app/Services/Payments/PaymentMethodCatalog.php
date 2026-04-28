<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurposeType;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentMethodCatalog
{
    /**
     * @var array<string, bool>
     */
    protected static array $preferredFallbackWarnings = [];

    public function __construct(
        protected PaymentProviderManager $providers,
    ) {
    }

    public function flushCache(): void
    {
        Cache::forget($this->cacheKey('all'));
    }

    public function all(): Collection
    {
        return Cache::remember($this->cacheKey('all'), now()->addMinutes(10), function () {
            return PaymentMethod::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        });
    }

    public function find(string $code): ?PaymentMethod
    {
        $code = strtolower(trim($code));

        return $this->all()->firstWhere('code', $code);
    }

    public function firstByDriver(string $driver, bool $requireWebhooks = false): ?PaymentMethod
    {
        $driver = strtolower(trim($driver));

        return $this->all()
            ->filter(fn (PaymentMethod $method) => $method->driver === $driver)
            ->filter(fn (PaymentMethod $method) => ! $requireWebhooks || $method->supports_webhooks)
            ->sortBy(fn (PaymentMethod $method) => sprintf('%05d-%s', (int) $method->sort_order, strtolower((string) $method->name)))
            ->first();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function availableForPurpose(
        PaymentPurposeType|string $purposeType,
        string $currencyCode = 'IQD',
        bool $visibleOnly = true,
        bool $checkoutReadyOnly = true,
    ): Collection {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        return $this->all()
            ->filter(fn (PaymentMethod $method) => $method->is_active)
            ->filter(fn (PaymentMethod $method) => ! $visibleOnly || $method->is_visible)
            ->filter(fn (PaymentMethod $method) => $method->supportsPurchaseType($purposeType))
            ->filter(fn (PaymentMethod $method) => $method->supportsCurrency($currencyCode))
            ->filter(fn (PaymentMethod $method) => ! $checkoutReadyOnly || $this->providers->checkoutReady($method))
            ->values();
    }

    public function defaultForPurpose(
        PaymentPurposeType|string $purposeType,
        string $currencyCode = 'IQD',
        ?string $preferredCode = null,
    ): ?PaymentMethod {
        $methods = $this->availableForPurpose($purposeType, $currencyCode);
        $normalizedPurpose = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        $preferredCode = strtolower(trim((string) ($preferredCode ?: config('payments.default_provider', ''))));

        if ($preferredCode !== '') {
            $preferred = $methods->first(function (PaymentMethod $method) use ($preferredCode) {
                return $method->code === $preferredCode || $method->driver === $preferredCode;
            });

            if ($preferred instanceof PaymentMethod) {
                return $preferred;
            }

            $this->warnUnavailablePreferredProvider($normalizedPurpose, $currencyCode, $preferredCode, $methods);
        }

        return $methods->first();
    }

    public function resolveCheckoutMethod(
        PaymentPurposeType|string $purposeType,
        string $currencyCode = 'IQD',
        ?string $methodCode = null,
        ?string $providerCode = null,
    ): ?PaymentMethod {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        $methods = $this->availableForPurpose($purposeType, $currencyCode);
        $methodCode = strtolower(trim((string) $methodCode));
        $providerCode = strtolower(trim((string) $providerCode));

        if ($methodCode !== '') {
            return $methods->first(fn (PaymentMethod $method) => $method->code === $methodCode);
        }

        if ($providerCode !== '') {
            return $methods->first(fn (PaymentMethod $method) => $method->driver === $providerCode || $method->code === $providerCode);
        }

        return $this->defaultForPurpose($purposeType, $currencyCode);
    }

    /**
     * @return array<int, array{code:string,name:string,description:?string,icon:?string,driver:string}>
     */
    public function checkoutOptions(PaymentPurposeType|string $purposeType, string $currencyCode = 'IQD'): array
    {
        return $this->availableForPurpose($purposeType, $currencyCode)
            ->map(fn (PaymentMethod $method) => [
                'code' => (string) $method->code,
                'name' => (string) $method->name,
                'description' => $method->description ? (string) $method->description : null,
                'icon' => $method->icon ? (string) $method->icon : null,
                'driver' => (string) $method->driver,
            ])
            ->values()
            ->all();
    }

    protected function cacheKey(string $suffix): string
    {
        return "payment-method-catalog:{$suffix}";
    }

    /**
     * @param  Collection<int, PaymentMethod>  $methods
     */
    protected function warnUnavailablePreferredProvider(
        PaymentPurposeType $purposeType,
        string $currencyCode,
        string $preferredCode,
        Collection $methods,
    ): void {
        $fallback = $methods->first();
        $fallbackCode = $fallback instanceof PaymentMethod ? (string) $fallback->code : null;
        $fallbackDriver = $fallback instanceof PaymentMethod ? (string) $fallback->driver : null;

        $cacheKey = implode('|', [
            $purposeType->value,
            strtoupper(trim($currencyCode)),
            $preferredCode,
            (string) ($fallbackCode ?? ''),
            (string) ($fallbackDriver ?? ''),
        ]);

        if (isset(self::$preferredFallbackWarnings[$cacheKey])) {
            return;
        }

        self::$preferredFallbackWarnings[$cacheKey] = true;

        Log::warning('Configured payment default provider is unavailable for checkout; using fallback checkout-ready method.', [
            'preferred_provider' => $preferredCode,
            'preferred_enabled' => $this->providers->isEnabled($preferredCode),
            'purpose_type' => $purposeType->value,
            'currency_code' => strtoupper(trim($currencyCode)),
            'fallback_method_code' => $fallbackCode,
            'fallback_method_driver' => $fallbackDriver,
            'available_method_codes' => $methods->map(fn (PaymentMethod $method): string => (string) $method->code)->values()->all(),
        ]);
    }
}

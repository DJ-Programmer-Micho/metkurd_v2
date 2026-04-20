<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProviderInterface;
use App\Enums\PaymentProvider;
use App\Models\PaymentMethod;
use App\Services\Payments\Providers\AreebaPaymentProvider;
use App\Services\Payments\Providers\FakePaymentProvider;
use App\Services\Payments\Providers\FibPaymentProvider;

class PaymentProviderManager
{
    /**
     * @var array<string, PaymentProviderInterface>
     */
    protected array $instances = [];

    public function driver(string $driver): PaymentProviderInterface
    {
        $driver = strtolower(trim($driver));
        $providerClass = config("payments.providers.{$driver}.provider_class") ?: $this->defaultProviderClass($driver);

        if (! is_string($providerClass) || $providerClass === '') {
            throw new \InvalidArgumentException("Payment provider [{$driver}] is not configured.");
        }

        if (! array_key_exists($driver, $this->instances)) {
            $provider = app($providerClass);

            if (! $provider instanceof PaymentProviderInterface) {
                throw new \RuntimeException("Configured payment provider [{$driver}] must implement PaymentProviderInterface.");
            }

            $this->instances[$driver] = $provider;
        }

        return $this->instances[$driver];
    }

    public function isEnabled(string $driver): bool
    {
        $driver = strtolower(trim($driver));

        if (config()->has("payments.providers.{$driver}.enabled")) {
            return (bool) config("payments.providers.{$driver}.enabled", false);
        }

        return match ($driver) {
            PaymentProvider::FAKE->value => (bool) config('payments.fake_enabled', true),
            PaymentProvider::FIB->value => (bool) config('payments.providers.fib.enabled', false),
            PaymentProvider::AREEBA->value => (bool) config('payments.providers.areeba.enabled', false),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function driverOptions(): array
    {
        $providers = (array) config('payments.providers', []);

        if ($providers === []) {
            $providers = [
                PaymentProvider::FAKE->value => ['label' => 'Fake Payments'],
                PaymentProvider::FIB->value => ['label' => 'First Iraqi Bank'],
                PaymentProvider::AREEBA->value => ['label' => 'Areeba Cards'],
            ];
        }

        return collect($providers)
            ->mapWithKeys(function (array $config, string $driver) {
                $label = (string) ($config['label'] ?? strtoupper($driver));

                return [$driver => $label];
            })
            ->all();
    }

    public function checkoutReady(PaymentMethod $method): bool
    {
        return $this->isEnabled($method->driver)
            && $this->driver($method->driver)->isCheckoutReady($method);
    }

    public function configurationReady(PaymentMethod $method): bool
    {
        return $this->isEnabled($method->driver)
            && $this->driver($method->driver)->hasValidConfiguration($method);
    }

    /**
     * @return array<int, string>
     */
    public function configurationIssues(PaymentMethod $method): array
    {
        if (! $this->isEnabled($method->driver)) {
            return [__('Driver is disabled in environment configuration.')];
        }

        return $this->driver($method->driver)->configurationIssues($method);
    }

    protected function defaultProviderClass(string $driver): ?string
    {
        return match ($driver) {
            PaymentProvider::FAKE->value => FakePaymentProvider::class,
            PaymentProvider::FIB->value => FibPaymentProvider::class,
            PaymentProvider::AREEBA->value => AreebaPaymentProvider::class,
            default => null,
        };
    }
}

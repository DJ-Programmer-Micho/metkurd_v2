<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProviderInterface;
use App\Enums\PaymentCardOrigin;
use App\Enums\PaymentPurposeType;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

abstract class AbstractConfiguredPaymentProvider implements PaymentProviderInterface
{
    abstract public function driver(): string;

    public function hasValidConfiguration(?PaymentMethod $method = null): bool
    {
        return $this->configurationIssues($method) === [];
    }

    /**
     * @return array<int, string>
     */
    public function configurationIssues(?PaymentMethod $method = null): array
    {
        $issues = [];
        $config = $this->runtimeConfig($method);

        foreach ($this->requiredConfigKeys($method) as $key => $label) {
            $configKey = is_string($key) ? $key : $label;
            $humanLabel = is_string($key) ? $label : $this->humanizeConfigKey($configKey);
            $value = $config[$configKey] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                $issues[] = __('Missing :value', ['value' => $humanLabel]);
            }
        }

        return $issues;
    }

    public function isCheckoutReady(?PaymentMethod $method = null): bool
    {
        return $this->hasValidConfiguration($method) && $this->hasCheckoutImplementation($method);
    }

    public function recurringStrategy(PaymentMethod $method, PaymentPurposeType $purposeType): string
    {
        if ($purposeType->value === 'credit_product') {
            return 'none';
        }

        return $method->supports_recurring ? 'provider_token' : 'manual_renewal';
    }

    public function initializeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        Customer $customer,
        array $purpose,
        array $options = [],
    ): array {
        throw new \RuntimeException(__('The selected payment method adapter is not connected yet.'));
    }

    public function synchronizeCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        throw new \RuntimeException(__('The selected payment method adapter cannot sync payment status yet.'));
    }

    public function cancelCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        throw new \RuntimeException(__('The selected payment method adapter does not support canceling payments.'));
    }

    public function refundCheckout(
        PaymentIntent $intent,
        PaymentMethod $method,
        array $options = [],
    ): array {
        throw new \RuntimeException(__('The selected payment method adapter does not support refunds.'));
    }

    public function validateWebhookSignature(Request $request, ?PaymentMethod $method = null): ?bool
    {
        $config = $this->runtimeConfig($method);
        $secret = trim((string) ($config['callback_secret'] ?? $config['webhook_secret'] ?? ''));
        $headerName = trim((string) ($config['callback_secret_header'] ?? $config['webhook_secret_header'] ?? ''));

        if ($secret === '' || $headerName === '') {
            return null;
        }

        $actual = trim((string) $request->header($headerName, ''));

        if ($actual === '') {
            return false;
        }

        return hash_equals($secret, $actual);
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return (array) config('payments.providers.' . $this->driver(), []);
    }

    /**
     * Runtime configuration merges immutable app config with optional DB-backed
     * non-secret settings, keeping credentials in config/.env while allowing
     * payment-method records to tweak safe behavior.
     *
     * @return array<string, mixed>
     */
    protected function runtimeConfig(?PaymentMethod $method = null): array
    {
        return array_replace_recursive(
            $this->config(),
            is_array($method?->settings) ? $method->settings : [],
        );
    }

    protected function normalizeCardOrigin(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'local', 'domestic' => PaymentCardOrigin::LOCAL->value,
            'international', 'foreign', 'intl' => PaymentCardOrigin::INTERNATIONAL->value,
            default => PaymentCardOrigin::UNKNOWN->value,
        };
    }

    /**
     * @return array<int|string, string>
     */
    protected function requiredConfigKeys(?PaymentMethod $method = null): array
    {
        return [];
    }

    protected function hasCheckoutImplementation(?PaymentMethod $method = null): bool
    {
        return false;
    }

    protected function humanizeConfigKey(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
}

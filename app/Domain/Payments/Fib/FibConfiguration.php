<?php

namespace App\Domain\Payments\Fib;

use InvalidArgumentException;

class FibConfiguration
{
    public function environment(): string
    {
        return (string) config('fib.environment', 'staging');
    }

    public function realm(): string
    {
        return trim((string) config('fib.realm', 'fib-online-shop'));
    }

    public function diagnosticsEnabled(): bool
    {
        return (bool) config('fib.diagnostics.enabled', false);
    }

    /**
     * @return array{
     *     name:string,
     *     base_url:string,
     *     base_url_source:?string,
     *     client_id:string,
     *     client_id_source:?string,
     *     client_secret:string,
     *     client_secret_source:?string
     * }
     */
    public function profile(string $profile): array
    {
        $profile = strtolower(trim($profile));
        $config = config("fib.profiles.{$profile}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown FIB profile [{$profile}].");
        }

        return [
            'name' => $profile,
            'base_url' => rtrim((string) ($config['base_url'] ?? ''), '/'),
            'base_url_source' => $this->nullableString($config['base_url_source'] ?? null),
            'client_id' => trim((string) ($config['client_id'] ?? '')),
            'client_id_source' => $this->nullableString($config['client_id_source'] ?? null),
            'client_secret' => trim((string) ($config['client_secret'] ?? '')),
            'client_secret_source' => $this->nullableString($config['client_secret_source'] ?? null),
        ];
    }

    public function baseUrl(string $profile): string
    {
        return $this->profile($profile)['base_url'];
    }

    public function clientId(string $profile): string
    {
        return $this->profile($profile)['client_id'];
    }

    public function clientSecret(string $profile): string
    {
        return $this->profile($profile)['client_secret'];
    }

    public function source(string $profile, string $key): ?string
    {
        return $this->profile($profile)["{$key}_source"] ?? null;
    }

    public function path(string $configKey, array $replacements = []): string
    {
        $path = (string) config("fib.paths.{$configKey}", '');

        foreach ($replacements as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        if ($configKey === 'token') {
            $path = str_replace('{realm}', rawurlencode($this->realm()), $path);
        }

        return $path;
    }

    public function url(string $profile, string $configKey, array $replacements = []): string
    {
        return rtrim($this->baseUrl($profile), '/') . '/' . ltrim($this->path($configKey, $replacements), '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function debugSummary(string $profile): array
    {
        $profileConfig = $this->profile($profile);

        return [
            'profile' => $profileConfig['name'],
            'environment' => $this->environment(),
            'base_url' => $profileConfig['base_url'],
            'base_url_source' => $profileConfig['base_url_source'],
            'token_url' => $this->url($profile, 'token'),
            'client_id_present' => $profileConfig['client_id'] !== '',
            'client_id_source' => $profileConfig['client_id_source'],
            'client_secret_present' => $profileConfig['client_secret'] !== '',
            'client_secret_source' => $profileConfig['client_secret_source'],
            'callback_base_url' => rtrim((string) config('fib.callback_base_url', config('app.url')), '/'),
            'paths' => [
                'payments' => $this->url($profile, 'payments'),
                'payment_status_example' => $this->url($profile, 'payment_status', ['paymentId' => 'example']),
                'payment_cancel_example' => $this->url($profile, 'payment_cancel', ['paymentId' => 'example']),
                'subscriptions' => $this->url($profile, 'subscriptions'),
                'subscription_status_example' => $this->url($profile, 'subscription_status', ['subscriptionId' => 'example']),
                'subscription_cancel_example' => $this->url($profile, 'subscription_cancel', ['subscriptionId' => 'example']),
            ],
            'warnings' => $this->warnings($profile),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function warnings(string $profile): array
    {
        $warnings = [];
        $profileConfig = $this->profile($profile);

        foreach (['base_url', 'client_id', 'client_secret'] as $key) {
            $source = $profileConfig["{$key}_source"] ?? null;

            if (is_string($source) && str_starts_with($source, 'legacy:')) {
                $warnings[] = "Using legacy {$key} fallback from {$source}.";
            }

            if ($key === 'base_url' && $source === 'default:official_docs') {
                $warnings[] = 'Using the official-docs default host. Override the per-profile base URL if your assigned staging tenant uses a different host.';
            }
        }

        $callbackBaseUrl = rtrim((string) config('fib.callback_base_url', config('app.url')), '/');

        if ($profile === 'subscription' && ($callbackBaseUrl === '' || str_contains($callbackBaseUrl, 'localhost') || str_starts_with($callbackBaseUrl, 'http://'))) {
            $warnings[] = 'Subscription callbacks require a public HTTPS callback base URL.';
        }

        return array_values(array_unique($warnings));
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}

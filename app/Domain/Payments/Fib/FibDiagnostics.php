<?php

namespace App\Domain\Payments\Fib;

use Illuminate\Support\Facades\Log;

class FibDiagnostics
{
    /**
     * @var array<string, bool>
     */
    protected static array $warningCache = [];

    public function __construct(
        protected FibConfiguration $config,
    ) {
    }

    public function debug(string $event, string $profile, array $context = []): void
    {
        if (! $this->config->diagnosticsEnabled()) {
            return;
        }

        Log::debug('FIB diagnostics', array_merge(
            ['event' => $event],
            $this->safeProfileContext($profile),
            $context,
        ));
    }

    public function warnOnAmbiguousProfile(string $profile): void
    {
        foreach ($this->config->warnings($profile) as $warning) {
            $key = sha1($profile . '|' . $warning);

            if (isset(self::$warningCache[$key])) {
                continue;
            }

            self::$warningCache[$key] = true;

            Log::warning('FIB configuration warning', array_merge(
                ['warning' => $warning],
                $this->safeProfileContext($profile),
            ));
        }
    }

    public function error(string $event, string $profile, array $payload = [], array $context = []): void
    {
        Log::error('FIB provider request failed', array_merge(
            ['event' => $event],
            $this->safeProfileContext($profile),
            $this->payloadContext($payload),
            $context,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function safeProfileContext(string $profile): array
    {
        $profileConfig = $this->config->profile($profile);
        $paymentProfile = $profile === 'payment'
            ? $profileConfig
            : $this->config->profile('payment');

        return [
            'profile' => $profileConfig['name'],
            'provider_object_type' => $profileConfig['name'] === 'subscription' ? 'subscription' : 'payment',
            'environment' => $this->config->environment(),
            'base_url' => $profileConfig['base_url'],
            'base_url_source' => $profileConfig['base_url_source'],
            'client_id' => $profileConfig['client_id'],
            'client_id_source' => $profileConfig['client_id_source'],
            'client_secret_present' => $profileConfig['client_secret'] !== '',
            'uses_legacy_base_url' => $this->usesLegacySource($profileConfig['base_url_source'] ?? null),
            'uses_legacy_client_id' => $this->usesLegacySource($profileConfig['client_id_source'] ?? null),
            'uses_legacy_client_secret' => $this->usesLegacySource($profileConfig['client_secret_source'] ?? null),
            'shares_payment_client_id' => $profileConfig['name'] === 'subscription'
                && $profileConfig['client_id'] !== ''
                && $profileConfig['client_id'] === ($paymentProfile['client_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payloadContext(array $payload): array
    {
        $traceId = trim((string) data_get($payload, 'traceId', ''));
        $errors = data_get($payload, 'errors', []);
        $codes = [];
        $titles = [];
        $details = [];

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $code = trim((string) data_get($error, 'code', ''));
                $title = trim((string) data_get($error, 'title', ''));
                $detail = trim((string) (
                    data_get($error, 'detail')
                    ?? data_get($error, 'details')
                    ?? data_get($error, 'message')
                    ?? ''
                ));

                if ($code !== '') {
                    $codes[] = $code;
                }

                if ($title !== '') {
                    $titles[] = $title;
                }

                if ($detail !== '') {
                    $details[] = $detail;
                }
            }
        }

        $fallbackMessage = trim((string) (
            data_get($payload, 'message')
            ?? data_get($payload, 'error_description')
            ?? data_get($payload, 'error')
            ?? data_get($payload, 'detail')
            ?? ''
        ));

        if ($fallbackMessage !== '') {
            $details[] = $fallbackMessage;
        }

        return [
            'trace_id' => $traceId !== '' ? $traceId : null,
            'error_codes' => array_values(array_unique(array_filter($codes))),
            'error_titles' => array_values(array_unique(array_filter($titles))),
            'error_details' => array_values(array_unique(array_filter($details))),
        ];
    }

    protected function usesLegacySource(?string $source): bool
    {
        return is_string($source) && str_starts_with($source, 'legacy:');
    }
}

<?php

namespace App\Domain\Payments\Fib;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

abstract class FibAuthorizedClient
{
    public function __construct(
        protected HttpFactory $http,
        protected FibTokenService $tokens,
        protected FibConfiguration $config,
    ) {
    }

    protected function authorized(): PendingRequest
    {
        $profile = $this->tokenProfile();
        $baseUrl = $this->config->baseUrl($profile);

        return $this->http
            ->baseUrl($baseUrl)
            ->timeout((int) config('fib.http.timeout', 15))
            ->retry(
                (int) config('fib.http.retries', 2),
                (int) config('fib.http.retry_sleep_ms', 200),
                fn () => true
            )
            ->withToken($this->tokens->getToken($profile)->accessToken);
    }

    protected function tokenProfile(): string
    {
        return 'payment';
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function path(string $configKey, array $replacements = []): string
    {
        $path = $this->config->path($configKey);

        foreach ($replacements as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode($value), $path);
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDiagnostics(string $event, array $context = []): void
    {
        if (! $this->config->diagnosticsEnabled()) {
            return;
        }

        $profile = $this->tokenProfile();
        $profileConfig = $this->config->profile($profile);

        Log::debug('FIB diagnostics', array_merge([
            'event' => $event,
            'profile' => $profile,
            'environment' => $this->config->environment(),
            'base_url' => $profileConfig['base_url'],
            'base_url_source' => $profileConfig['base_url_source'],
            'client_id_source' => $profileConfig['client_id_source'],
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function errorMessage(string $fallback, array $payload): string
    {
        $details = [];
        $traceId = trim((string) data_get($payload, 'traceId', ''));
        $errors = data_get($payload, 'errors', []);

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $parts = array_filter([
                    trim((string) data_get($error, 'code', '')),
                    trim((string) data_get($error, 'title', '')),
                    trim((string) (
                        data_get($error, 'detail')
                        ?? data_get($error, 'details')
                        ?? data_get($error, 'message')
                        ?? ''
                    )),
                ]);

                if ($parts !== []) {
                    $details[] = implode(' - ', $parts);
                }
            }
        }

        $fallbackDetail = trim((string) (
            data_get($payload, 'message')
            ?? data_get($payload, 'error_description')
            ?? data_get($payload, 'error')
            ?? data_get($payload, 'detail')
            ?? ''
        ));

        if ($fallbackDetail !== '') {
            $details[] = $fallbackDetail;
        }

        $details = array_values(array_unique(array_filter($details)));
        $detailMessage = $details !== [] ? implode(' | ', $details) : '';

        if ($traceId !== '') {
            $detailMessage = trim($detailMessage . " [traceId: {$traceId}]");
        }

        return $detailMessage !== '' ? "{$fallback} {$detailMessage}" : $fallback;
    }
}

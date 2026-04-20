<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibTokenData;
use App\Domain\Payments\Exceptions\FibApiException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FibTokenService
{
    public function __construct(
        protected HttpFactory $http,
        protected FibConfiguration $config,
    ) {
    }

    public function getToken(string $profile = 'payment'): FibTokenData
    {
        $profileConfig = $this->config->profile($profile);

        if ($profileConfig['client_id'] === '' || $profileConfig['client_secret'] === '') {
            throw new FibApiException("FIB {$profile} client credentials are not configured.");
        }

        if ($profileConfig['base_url'] === '') {
            throw new FibApiException("FIB {$profile} base URL is not configured.");
        }

        $cached = Cache::get($this->cacheKey($profile, $profileConfig['base_url'], $profileConfig['client_id']));

        if (is_array($cached) && filled($cached['access_token'] ?? null)) {
            return FibTokenData::fromArray($cached);
        }

        $this->logDiagnostics('token_request_start', $profile, [
            'base_url' => $profileConfig['base_url'],
            'base_url_source' => $profileConfig['base_url_source'],
            'client_id_source' => $profileConfig['client_id_source'],
            'token_url' => $this->config->url($profile, 'token'),
        ]);

        $response = $this->http
            ->baseUrl($profileConfig['base_url'])
            ->timeout((int) config('fib.http.timeout', 15))
            ->retry(
                (int) config('fib.http.retries', 2),
                (int) config('fib.http.retry_sleep_ms', 200),
                fn () => true
            )
            ->asForm()
            ->post($this->config->path('token'), [
                'grant_type' => 'client_credentials',
                'client_id' => $profileConfig['client_id'],
                'client_secret' => $profileConfig['client_secret'],
            ]);

        $payload = $response->json() ?? ['body' => $response->body()];

        if (! $response->successful()) {
            throw new FibApiException($this->errorMessage('FIB authentication failed.', $payload));
        }

        $token = FibTokenData::fromArray($payload);

        if ($token->accessToken === '') {
            throw new FibApiException('FIB did not return an access token.');
        }

        $ttlSeconds = max(
            5,
            min(
                max(5, $token->expiresIn - 5),
                (int) config('fib.token_ttl_seconds', 60)
            )
        );

        Cache::put($this->cacheKey($profile, $profileConfig['base_url'], $profileConfig['client_id']), [
            'access_token' => $token->accessToken,
            'expires_in' => $token->expiresIn,
            'token_type' => $token->tokenType,
            'scope' => $token->scope,
        ], now()->addSeconds($ttlSeconds));

        return $token;
    }

    protected function cacheKey(string $profile = 'payment', ?string $baseUrl = null, ?string $clientId = null): string
    {
        return sprintf(
            'fib:token:%s:%s:%s:%s',
            sha1((string) ($baseUrl ?? $this->config->baseUrl($profile))),
            sha1((string) $this->config->realm()),
            sha1($profile),
            sha1((string) ($clientId ?? $this->config->clientId($profile))),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function errorMessage(string $fallback, array $payload): string
    {
        $details = trim((string) (
            data_get($payload, 'message')
            ?? data_get($payload, 'error_description')
            ?? data_get($payload, 'error')
            ?? data_get($payload, 'detail')
            ?? ''
        ));

        return $details !== '' ? "{$fallback} {$details}" : $fallback;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDiagnostics(string $event, string $profile, array $context = []): void
    {
        if (! $this->config->diagnosticsEnabled()) {
            return;
        }

        Log::debug('FIB diagnostics', array_merge([
            'event' => $event,
            'profile' => $profile,
            'environment' => $this->config->environment(),
        ], $context));
    }
}

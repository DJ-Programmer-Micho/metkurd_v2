<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibTokenData;
use App\Domain\Payments\Exceptions\FibApiException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

class FibTokenService
{
    public function __construct(
        protected HttpFactory $http,
        protected FibConfiguration $config,
        protected FibDiagnostics $diagnostics,
    ) {}

    public function getToken(string $profile = 'payment'): FibTokenData
    {
        $profileConfig = $this->config->profile($profile);

        if ($profileConfig['client_id'] === '' || $profileConfig['client_secret'] === '') {
            throw new FibApiException("FIB {$profile} client credentials are not configured.");
        }

        if ($profileConfig['base_url'] === '') {
            throw new FibApiException("FIB {$profile} base URL is not configured.");
        }

        $this->diagnostics->warnOnAmbiguousProfile($profile);

        $cached = Cache::get($this->cacheKey($profile, $profileConfig['base_url'], $profileConfig['client_id']));

        if (is_array($cached) && filled($cached['access_token'] ?? null)) {
            $this->diagnostics->debug('token_cache_hit', $profile, [
                'token_url' => $this->config->url($profile, 'token'),
            ]);

            return FibTokenData::fromArray($cached);
        }

        $this->diagnostics->debug('token_request_start', $profile, [
            'base_url' => $profileConfig['base_url'],
            'base_url_source' => $profileConfig['base_url_source'],
            'token_url' => $this->config->url($profile, 'token'),
            'client_id' => $profileConfig['client_id'],
            'credentials_present' => $profileConfig['client_id'] !== '' && $profileConfig['client_secret'] !== '',
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
            $this->diagnostics->error('token_request_failed', $profile, $payload, [
                'token_url' => $this->config->url($profile, 'token'),
                'response_status' => $response->status(),
            ]);

            throw new FibApiException(
                $this->errorMessage('FIB authentication failed.', $payload),
                $payload,
                [
                    'profile' => $profile,
                    'token_url' => $this->config->url($profile, 'token'),
                    'response_status' => $response->status(),
                ],
                $response->status(),
            );
        }

        $token = FibTokenData::fromArray($payload);

        if ($token->accessToken === '') {
            $this->diagnostics->error('token_missing_access_token', $profile, $payload, [
                'token_url' => $this->config->url($profile, 'token'),
                'response_status' => $response->status(),
            ]);

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

        $this->diagnostics->debug('token_request_succeeded', $profile, [
            'token_url' => $this->config->url($profile, 'token'),
            'response_status' => $response->status(),
            'expires_in' => $token->expiresIn,
            'cache_ttl_seconds' => $ttlSeconds,
        ]);

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
}

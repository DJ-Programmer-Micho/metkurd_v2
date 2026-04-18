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
    ) {
    }

    public function getToken(): FibTokenData
    {
        $cached = Cache::get($this->cacheKey());

        if (is_array($cached) && filled($cached['access_token'] ?? null)) {
            return FibTokenData::fromArray($cached);
        }

        $response = $this->http
            ->baseUrl((string) config('services.fib.base_url'))
            ->timeout((int) config('services.fib.http.timeout', 15))
            ->retry(
                (int) config('services.fib.http.retries', 2),
                (int) config('services.fib.http.retry_sleep_ms', 200),
                fn () => true
            )
            ->asForm()
            ->post((string) config('services.fib.paths.token'), [
                'grant_type' => 'client_credentials',
                'client_id' => (string) config('services.fib.client_id'),
                'client_secret' => (string) config('services.fib.client_secret'),
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
                (int) config('services.fib.token_ttl_seconds', 60)
            )
        );

        Cache::put($this->cacheKey(), [
            'access_token' => $token->accessToken,
            'expires_in' => $token->expiresIn,
            'token_type' => $token->tokenType,
            'scope' => $token->scope,
        ], now()->addSeconds($ttlSeconds));

        return $token;
    }

    protected function cacheKey(): string
    {
        return sprintf(
            'fib:token:%s:%s:%s',
            sha1((string) config('services.fib.base_url')),
            sha1((string) config('services.fib.realm')),
            sha1((string) config('services.fib.client_id')),
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

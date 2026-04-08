<?php

namespace App\Services\Payments\Fib;

use App\Contracts\Payments\FibGatewayInterface;
use App\Services\Payments\Fib\Data\FibCancelPaymentRequest;
use App\Services\Payments\Fib\Data\FibCreatePaymentRequest;
use App\Services\Payments\Fib\Data\FibPaymentData;
use App\Services\Payments\Fib\Data\FibRefundPaymentRequest;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

class FibHttpGateway implements FibGatewayInterface
{
    public function __construct(
        protected HttpFactory $http,
    ) {
    }

    public function configurationIssues(): array
    {
        $config = $this->config();
        $issues = [];

        foreach ([
            'base_url' => 'Base URL',
            'client_id' => 'Client ID',
            'client_secret' => 'Client Secret',
            'realm' => 'Realm',
        ] as $key => $label) {
            $value = $config[$key] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                $issues[] = __('Missing :value', ['value' => $label]);
            }
        }

        return $issues;
    }

    public function createPayment(FibCreatePaymentRequest $request): FibPaymentData
    {
        $response = $this->authorizedHttp()
            ->acceptJson()
            ->post($this->path('payments'), $request->toArray());

        return $this->handlePaymentResponse(
            $response->status(),
            $response->json() ?? ['body' => $response->body()],
            __('FIB rejected the payment initialization request.'),
        );
    }

    public function getPaymentStatus(string $paymentId): FibPaymentData
    {
        $response = $this->authorizedHttp()
            ->acceptJson()
            ->get($this->path('payment_status', ['paymentId' => $paymentId]));

        return $this->handlePaymentResponse(
            $response->status(),
            $response->json() ?? ['body' => $response->body()],
            __('FIB status sync failed.'),
        );
    }

    public function cancelPayment(FibCancelPaymentRequest $request): FibPaymentData
    {
        $response = $this->authorizedHttp()
            ->acceptJson()
            ->post($this->path('payment_cancel', ['paymentId' => $request->paymentId]), $request->toArray());

        if ($response->status() === 204) {
            return FibPaymentData::fromArray([
                'paymentId' => $request->paymentId,
                'status' => 'CANCELLED',
            ]);
        }

        return $this->handlePaymentResponse(
            $response->status(),
            $response->json() ?? ['body' => $response->body()],
            __('FIB cancel request failed.'),
        );
    }

    public function refundPayment(FibRefundPaymentRequest $request): FibPaymentData
    {
        $response = $this->authorizedHttp()
            ->acceptJson()
            ->post($this->path('payment_refund', ['paymentId' => $request->paymentId]), $request->toArray());

        if ($response->status() === 204) {
            return FibPaymentData::fromArray([
                'paymentId' => $request->paymentId,
                'status' => 'REFUNDED',
            ]);
        }

        return $this->handlePaymentResponse(
            $response->status(),
            $response->json() ?? ['body' => $response->body()],
            __('FIB refund request failed.'),
        );
    }

    protected function accessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        $response = $this->baseHttp()
            ->asForm()
            ->post($this->path('token', ['realm' => (string) config('fib.realm')]), [
                'grant_type' => 'client_credentials',
                'client_id' => (string) config('fib.client_id'),
                'client_secret' => (string) config('fib.client_secret'),
            ]);

        $payload = $response->json() ?? ['body' => $response->body()];

        if (! $response->successful()) {
            throw new \RuntimeException($this->providerErrorMessage(
                __('FIB authentication failed.'),
                $payload,
            ));
        }

        $token = trim((string) data_get($payload, 'access_token', ''));

        if ($token === '') {
            throw new \RuntimeException(__('FIB did not return an access token.'));
        }

        $expiresIn = (int) data_get($payload, 'expires_in', (int) config('fib.token_ttl_seconds', 60));
        $ttlSeconds = max(5, min(max(5, $expiresIn - 5), (int) config('fib.token_ttl_seconds', 60)));

        Cache::put($cacheKey, $token, now()->addSeconds($ttlSeconds));

        return $token;
    }

    protected function tokenCacheKey(): string
    {
        return sprintf(
            'fib:token:%s:%s:%s:%s',
            (string) config('fib.environment', 'staging'),
            sha1((string) config('fib.base_url', '')),
            sha1((string) config('fib.realm', '')),
            sha1((string) config('fib.client_id', '')),
        );
    }

    protected function baseHttp(): PendingRequest
    {
        return $this->http
            ->baseUrl((string) config('fib.base_url'))
            ->timeout((int) config('fib.http.timeout', 15))
            ->retry(
                (int) config('fib.http.retries', 2),
                (int) config('fib.http.retry_sleep_ms', 200),
                fn () => true
            );
    }

    protected function authorizedHttp(): PendingRequest
    {
        return $this->baseHttp()->withToken($this->accessToken());
    }

    /**
     * @param  array<string, string>  $vars
     */
    protected function path(string $key, array $vars = []): string
    {
        $template = (string) data_get(config('fib.paths', []), $key, '');
        $vars = array_merge(['realm' => (string) config('fib.realm', 'fib-online-shop')], $vars);

        foreach ($vars as $name => $value) {
            $template = str_replace('{' . $name . '}', rawurlencode((string) $value), $template);
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return (array) config('fib', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function providerErrorMessage(string $fallback, array $payload): string
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
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentResponse(int $statusCode, array $payload, string $fallback): FibPaymentData
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException($this->providerErrorMessage($fallback, $payload));
        }

        $data = FibPaymentData::fromArray($payload);

        if ($data->paymentId === '') {
            throw new \RuntimeException(__('FIB did not return a payment identifier.'));
        }

        return $data;
    }
}

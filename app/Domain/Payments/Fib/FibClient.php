<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\FibCreatePaymentRequestData;
use App\Domain\Payments\Data\FibCreatePaymentResponseData;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Exceptions\FibApiException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

class FibClient implements PaymentGateway
{
    public function __construct(
        protected HttpFactory $http,
        protected FibTokenService $tokens,
    ) {
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::FIB;
    }

    public function createPayment(FibCreatePaymentRequestData $request): FibCreatePaymentResponseData
    {
        $response = $this->authorized()
            ->acceptJson()
            ->post((string) config('services.fib.paths.payments'), $request->toArray());

        $payload = $response->json() ?? ['body' => $response->body()];

        if ($response->status() !== 201) {
            throw new FibApiException($this->errorMessage('FIB rejected the payment creation request.', $payload));
        }

        $data = FibCreatePaymentResponseData::fromArray($payload);

        if ($data->paymentId === '') {
            throw new FibApiException('FIB did not return a payment identifier.');
        }

        return $data;
    }

    public function getPaymentStatus(string $providerPaymentId): FibPaymentStatusData
    {
        $response = $this->authorized()
            ->acceptJson()
            ->get(str_replace('{paymentId}', rawurlencode($providerPaymentId), (string) config('services.fib.paths.payment_status')));

        $payload = $response->json() ?? ['body' => $response->body()];

        if (! $response->successful()) {
            throw new FibApiException($this->errorMessage('FIB status check failed.', $payload));
        }

        $data = FibPaymentStatusData::fromArray($payload);

        if ($data->paymentId === '') {
            throw new FibApiException('FIB status response did not include a payment identifier.');
        }

        return $data;
    }

    public function cancelPayment(string $providerPaymentId): void
    {
        $response = $this->authorized()
            ->acceptJson()
            ->post(str_replace('{paymentId}', rawurlencode($providerPaymentId), (string) config('services.fib.paths.payment_cancel')));

        if ($response->status() !== 204) {
            $payload = $response->json() ?? ['body' => $response->body()];

            throw new FibApiException($this->errorMessage('FIB cancel request failed.', $payload));
        }
    }

    protected function authorized(): PendingRequest
    {
        return $this->http
            ->baseUrl((string) config('services.fib.base_url'))
            ->timeout((int) config('services.fib.http.timeout', 15))
            ->retry(
                (int) config('services.fib.http.retries', 2),
                (int) config('services.fib.http.retry_sleep_ms', 200),
                fn () => true
            )
            ->withToken($this->tokens->getToken()->accessToken);
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

<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\FibCreatePaymentRequestData;
use App\Domain\Payments\Data\FibCreatePaymentResponseData;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Exceptions\FibApiException;

class FibOneTimePaymentClient extends FibAuthorizedClient implements PaymentGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::FIB;
    }

    public function createPayment(FibCreatePaymentRequestData $request): FibCreatePaymentResponseData
    {
        $this->logDiagnostics('payment_create_request', [
            'url' => $this->config->url($this->tokenProfile(), 'payments'),
        ]);

        $response = $this->authorized()
            ->acceptJson()
            ->post($this->path('payments'), $request->toArray());

        $payload = $response->json() ?? ['body' => $response->body()];

        if ($response->status() !== 201) {
            $this->reportProviderFailure('payment_create_failed', $payload, [
                'response_status' => $response->status(),
                'url' => $this->config->url($this->tokenProfile(), 'payments'),
            ]);

            throw new FibApiException($this->errorMessage('FIB rejected the payment creation request.', $payload), $payload);
        }

        $data = FibCreatePaymentResponseData::fromArray($payload);

        if ($data->paymentId === '') {
            throw new FibApiException('FIB did not return a payment identifier.');
        }

        return $data;
    }

    public function getPaymentStatus(string $providerPaymentId): FibPaymentStatusData
    {
        $this->logDiagnostics('payment_status_request', [
            'url' => $this->config->url($this->tokenProfile(), 'payment_status', ['paymentId' => $providerPaymentId]),
        ]);

        $response = $this->authorized()
            ->acceptJson()
            ->get($this->path('payment_status', ['paymentId' => $providerPaymentId]));

        $payload = $response->json() ?? ['body' => $response->body()];

        if (! $response->successful()) {
            $this->reportProviderFailure('payment_status_failed', $payload, [
                'response_status' => $response->status(),
                'url' => $this->config->url($this->tokenProfile(), 'payment_status', ['paymentId' => $providerPaymentId]),
                'provider_payment_id' => $providerPaymentId,
            ]);

            throw new FibApiException(
                $this->errorMessage('FIB status check failed.', $payload),
                $payload,
                [
                    'response_status' => $response->status(),
                    'url' => $this->config->url($this->tokenProfile(), 'payment_status', ['paymentId' => $providerPaymentId]),
                    'provider_payment_id' => $providerPaymentId,
                ],
                $response->status(),
            );
        }

        $data = FibPaymentStatusData::fromArray($payload);

        if ($data->paymentId === '') {
            throw new FibApiException('FIB status response did not include a payment identifier.');
        }

        return $data;
    }

    public function cancelPayment(string $providerPaymentId): void
    {
        $this->logDiagnostics('payment_cancel_request', [
            'url' => $this->config->url($this->tokenProfile(), 'payment_cancel', ['paymentId' => $providerPaymentId]),
        ]);

        $response = $this->authorized()
            ->acceptJson()
            ->post($this->path('payment_cancel', ['paymentId' => $providerPaymentId]));

        if ($response->status() !== 204) {
            $payload = $response->json() ?? ['body' => $response->body()];

            $this->reportProviderFailure('payment_cancel_failed', $payload, [
                'response_status' => $response->status(),
                'url' => $this->config->url($this->tokenProfile(), 'payment_cancel', ['paymentId' => $providerPaymentId]),
                'provider_payment_id' => $providerPaymentId,
            ]);

            throw new FibApiException($this->errorMessage('FIB cancel request failed.', $payload), $payload);
        }
    }
}

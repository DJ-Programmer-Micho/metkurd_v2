<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibCreateSubscriptionRequestData;
use App\Domain\Payments\Data\FibCreateSubscriptionResponseData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Exceptions\FibApiException;

class FibSubscriptionClient extends FibAuthorizedClient
{
    protected function tokenProfile(): string
    {
        return 'subscription';
    }

    public function createSubscription(FibCreateSubscriptionRequestData $request): FibCreateSubscriptionResponseData
    {
        $this->logDiagnostics('subscription_create_request', [
            'url' => $this->config->url($this->tokenProfile(), 'subscriptions'),
        ]);

        $response = $this->authorized()
            ->acceptJson()
            ->post($this->path('subscriptions'), $request->toArray());

        $payload = $response->json() ?? ['body' => $response->body()];

        if ($response->status() !== 201) {
            throw new FibApiException($this->errorMessage('FIB rejected the subscription creation request.', $payload));
        }

        $data = FibCreateSubscriptionResponseData::fromArray($payload);

        if ($data->subscriptionId === '') {
            throw new FibApiException('FIB did not return a subscription identifier.');
        }

        return $data;
    }

    public function getSubscription(string $providerSubscriptionId): FibSubscriptionStatusData
    {
        $this->logDiagnostics('subscription_status_request', [
            'url' => $this->config->url($this->tokenProfile(), 'subscription_status', ['subscriptionId' => $providerSubscriptionId]),
        ]);

        $response = $this->authorized()
            ->acceptJson()
            ->get($this->path('subscription_status', ['subscriptionId' => $providerSubscriptionId]));

        $payload = $response->json() ?? ['body' => $response->body()];

        if (! $response->successful()) {
            throw new FibApiException($this->errorMessage('FIB subscription status check failed.', $payload));
        }

        $data = FibSubscriptionStatusData::fromArray($payload);

        if ($data->subscriptionId === '') {
            throw new FibApiException('FIB subscription status response did not include a subscription identifier.');
        }

        return $data;
    }

    public function cancelSubscription(string $providerSubscriptionId): void
    {
        $this->logDiagnostics('subscription_cancel_request', [
            'url' => $this->config->url($this->tokenProfile(), 'subscription_cancel', ['subscriptionId' => $providerSubscriptionId]),
        ]);

        $response = $this->authorized()
            ->acceptJson()
            ->post($this->path('subscription_cancel', ['subscriptionId' => $providerSubscriptionId]));

        if (! in_array($response->status(), [200, 202, 204], true)) {
            $payload = $response->json() ?? ['body' => $response->body()];

            throw new FibApiException($this->errorMessage('FIB subscription cancel request failed.', $payload));
        }
    }
}

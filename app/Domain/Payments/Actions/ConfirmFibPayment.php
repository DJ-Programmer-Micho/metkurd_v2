<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Models\Payment;

class ConfirmFibPayment
{
    public function __construct(
        protected SyncFibCheckoutStatus $sync,
    ) {
    }

    public function handle(Payment $payment, string $source = 'manual_status_refresh', ?array $callbackPayload = null): Payment
    {
        return $this->sync->handle($payment, $source, $callbackPayload);
    }

    public function handleByFibPaymentId(string $fibPaymentId, string $source = 'callback', ?array $callbackPayload = null): ?Payment
    {
        return $this->sync->handleByFibPaymentId($fibPaymentId, $source, $callbackPayload);
    }

    public function handleByFibSubscriptionId(string $fibSubscriptionId, string $source = 'callback', ?array $callbackPayload = null): ?Payment
    {
        return $this->sync->handleByFibSubscriptionId($fibSubscriptionId, $source, $callbackPayload);
    }
}

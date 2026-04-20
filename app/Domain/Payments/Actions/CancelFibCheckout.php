<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;

class CancelFibCheckout
{
    public function __construct(
        protected FibOneTimePaymentService $oneTime,
        protected FibSubscriptionService $subscriptions,
        protected SyncFibCheckoutStatus $sync,
        protected PaymentEventRecorder $events,
    ) {
    }

    public function handle(Payment $payment, string $source = 'manual_cancel'): Payment
    {
        $payment = $payment->fresh() ?? $payment;

        if ($payment->isTerminal() && $payment->status !== PaymentStatus::AWAITING_CUSTOMER_ACTION) {
            return $payment;
        }

        if ($payment->isProviderSubscriptionObject()) {
            $this->subscriptions->cancel($payment);
        } else {
            $this->oneTime->cancel($payment);
        }

        $payment->forceFill([
            'cancel_response' => [
                'accepted' => true,
                'source' => $source,
                'provider_object_type' => ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->value,
                'accepted_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->events->record($payment, [
            'event_type' => 'provider_cancel_requested',
            'source' => $source,
            'before_status' => $payment->status->value,
            'after_status' => $payment->status->value,
            'meta' => [
                'accepted' => true,
                'provider_object_type' => ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->value,
            ],
        ]);

        try {
            return $this->sync->handle($payment, 'cancel_confirmation');
        } catch (\Throwable) {
            return $payment->fresh();
        }
    }
}

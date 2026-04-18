<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;

class CancelFibPayment
{
    public function __construct(
        protected \App\Domain\Payments\Fib\FibPaymentService $fib,
        protected ConfirmFibPayment $confirm,
        protected PaymentEventRecorder $events,
    ) {
    }

    public function handle(Payment $payment, string $source = 'manual_cancel'): Payment
    {
        $payment = $payment->fresh() ?? $payment;

        if ($payment->isTerminal() && $payment->status !== PaymentStatus::AWAITING_CUSTOMER_ACTION) {
            return $payment;
        }

        $this->fib->cancel($payment);

        $payment->forceFill([
            'cancel_response' => [
                'accepted' => true,
                'source' => $source,
                'accepted_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->events->record($payment, [
            'event_type' => 'payment_cancel_requested',
            'source' => $source,
            'before_status' => $payment->status->value,
            'after_status' => $payment->status->value,
            'meta' => [
                'accepted' => true,
            ],
        ]);

        try {
            return $this->confirm->handle($payment, 'cancel_confirmation');
        } catch (\Throwable) {
            return $payment->fresh();
        }
    }
}

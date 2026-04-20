<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Models\Payment;

class CancelFibPayment
{
    public function __construct(
        protected CancelFibCheckout $cancel,
    ) {
    }

    public function handle(Payment $payment, string $source = 'manual_cancel'): Payment
    {
        return $this->cancel->handle($payment, $source);
    }
}

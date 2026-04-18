<?php

namespace App\Events\Payments;

class PaymentConfirmed
{
    public function __construct(
        public readonly int $paymentId,
    ) {
    }
}

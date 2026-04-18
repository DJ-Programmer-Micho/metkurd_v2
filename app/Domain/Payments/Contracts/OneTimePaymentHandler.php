<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;

interface OneTimePaymentHandler
{
    public function supports(PurchaseType $purchaseType): bool;

    public function handle(Payment $payment): void;
}

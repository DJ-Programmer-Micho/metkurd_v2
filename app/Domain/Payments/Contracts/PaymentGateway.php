<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Data\FibCreatePaymentRequestData;
use App\Domain\Payments\Data\FibCreatePaymentResponseData;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Enums\PaymentProvider;

interface PaymentGateway
{
    public function provider(): PaymentProvider;

    public function createPayment(FibCreatePaymentRequestData $request): FibCreatePaymentResponseData;

    public function getPaymentStatus(string $providerPaymentId): FibPaymentStatusData;

    public function cancelPayment(string $providerPaymentId): void;
}

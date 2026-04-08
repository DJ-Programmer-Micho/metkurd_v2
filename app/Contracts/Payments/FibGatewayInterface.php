<?php

namespace App\Contracts\Payments;

use App\Services\Payments\Fib\Data\FibCancelPaymentRequest;
use App\Services\Payments\Fib\Data\FibCreatePaymentRequest;
use App\Services\Payments\Fib\Data\FibPaymentData;
use App\Services\Payments\Fib\Data\FibRefundPaymentRequest;

interface FibGatewayInterface
{
    /**
     * @return array<int, string>
     */
    public function configurationIssues(): array;

    public function createPayment(FibCreatePaymentRequest $request): FibPaymentData;

    public function getPaymentStatus(string $paymentId): FibPaymentData;

    public function cancelPayment(FibCancelPaymentRequest $request): FibPaymentData;

    public function refundPayment(FibRefundPaymentRequest $request): FibPaymentData;
}

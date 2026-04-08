<?php

namespace App\Services\Payments\Fib\Data;

class FibCreatePaymentRequest
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $callbackUrl,
        public readonly string $description,
    ) {
    }

    /**
     * Uses the current official FIB web-payments request shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monetaryValue' => [
                'amount' => $this->amount,
                'currency' => $this->currency,
            ],
            'statusCallbackUrl' => $this->callbackUrl,
            'description' => $this->description,
        ];
    }
}

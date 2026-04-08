<?php

namespace App\Services\Payments\Fib\Data;

class FibRefundPaymentRequest
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly ?int $amount = null,
        public readonly ?string $reason = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'amount' => $this->amount,
            'reason' => $this->reason,
        ], fn ($value) => $value !== null && (! is_string($value) || trim($value) !== ''));
    }
}

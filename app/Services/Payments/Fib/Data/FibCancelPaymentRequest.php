<?php

namespace App\Services\Payments\Fib\Data;

class FibCancelPaymentRequest
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $paymentId,
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
            'reason' => $this->reason,
        ], fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);
    }
}

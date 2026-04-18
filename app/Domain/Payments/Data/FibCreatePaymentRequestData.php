<?php

namespace App\Domain\Payments\Data;

final class FibCreatePaymentRequestData
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $statusCallbackUrl,
        public readonly ?string $description,
        public readonly ?string $redirectUri,
        public readonly ?string $expiresIn,
        public readonly ?string $category,
        public readonly ?string $refundableFor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'monetaryValue' => [
                'amount' => (string) $this->amount,
                'currency' => strtoupper($this->currency),
            ],
            'statusCallbackUrl' => $this->statusCallbackUrl,
            'description' => $this->description,
            'redirectUri' => $this->redirectUri,
            'expiresIn' => $this->expiresIn,
            'category' => $this->category,
            'refundableFor' => $this->refundableFor,
        ], static fn (mixed $value) => $value !== null && $value !== '');
    }
}

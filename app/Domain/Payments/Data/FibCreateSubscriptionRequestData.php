<?php

namespace App\Domain\Payments\Data;

final class FibCreateSubscriptionRequestData
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $interval,
        public readonly ?string $trialPeriod,
        public readonly ?string $expiresIn,
        public readonly string $statusCallbackUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'monetaryValue' => [
                'amount' => (string) $this->amount,
                'currency' => strtoupper($this->currency),
            ],
            'interval' => $this->interval,
            'trialPeriod' => $this->trialPeriod,
            'expiresIn' => $this->expiresIn,
            'statusCallbackUrl' => $this->statusCallbackUrl,
        ], static fn (mixed $value) => $value !== null && $value !== '');
    }
}

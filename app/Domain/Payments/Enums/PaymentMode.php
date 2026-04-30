<?php

namespace App\Domain\Payments\Enums;

enum PaymentMode: string
{
    case RECURRING = 'recurring';
    case ONE_TIME = 'one_time';

    public static function fromValue(mixed $value, self $fallback = self::ONE_TIME): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_scalar($value)) {
            return $fallback;
        }

        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? $fallback;
    }

    public function isRecurring(): bool
    {
        return $this === self::RECURRING;
    }

    public function isOneTime(): bool
    {
        return $this === self::ONE_TIME;
    }

    public function isManual(): bool
    {
        return $this === self::ONE_TIME;
    }

    public function isAuto(): bool
    {
        return $this === self::RECURRING;
    }

    public function label(): string
    {
        return $this->isAuto() ? 'Auto Renewal' : 'Manual Payment';
    }

    public function description(?string $billingInterval = null): string
    {
        if ($this->isAuto()) {
            return 'Automatically renews subscription.';
        }

        $interval = strtolower(trim((string) $billingInterval));

        return match ($interval) {
            'yearly' => 'Pay manually each year',
            'monthly' => 'Pay manually each month',
            default => 'No auto-renew. Pay manually each billing period.',
        };
    }
}

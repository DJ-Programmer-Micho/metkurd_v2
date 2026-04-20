<?php

namespace App\Domain\Payments\Enums;

enum PaymentProviderObjectType: string
{
    case PAYMENT = 'payment';
    case SUBSCRIPTION = 'subscription';

    public function isPayment(): bool
    {
        return $this === self::PAYMENT;
    }

    public function isSubscription(): bool
    {
        return $this === self::SUBSCRIPTION;
    }
}

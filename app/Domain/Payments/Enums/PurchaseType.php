<?php

namespace App\Domain\Payments\Enums;

enum PurchaseType: string
{
    case PLAN_SUBSCRIPTION = 'plan_subscription';
    case STORAGE_SUBSCRIPTION = 'storage_subscription';
    case ADDON_CREDITS = 'addon_credits';

    public function thankYouView(): string
    {
        return match ($this) {
            self::PLAN_SUBSCRIPTION => 'app.otp.thank-you-plan',
            self::STORAGE_SUBSCRIPTION => 'app.otp.thank-you-storage',
            self::ADDON_CREDITS => 'app.otp.thank-you-addon',
        };
    }
}

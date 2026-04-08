<?php

namespace App\Enums;

enum PaymentRecurringStrategy: string
{
    case NONE = 'none';
    case MANUAL_RENEWAL = 'manual_renewal';
    case PROVIDER_TOKEN = 'provider_token';
    case PROVIDER_SCHEDULE = 'provider_schedule';
}

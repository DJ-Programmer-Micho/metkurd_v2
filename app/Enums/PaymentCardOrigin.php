<?php

namespace App\Enums;

enum PaymentCardOrigin: string
{
    case LOCAL = 'local';
    case INTERNATIONAL = 'international';
    case UNKNOWN = 'unknown';
}

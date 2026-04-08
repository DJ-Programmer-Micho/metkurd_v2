<?php

namespace App\Enums;

enum PaymentIntentStatus: string
{
    case PENDING = 'pending';
    case REQUIRES_ACTION = 'requires_action';
    case PROCESSING = 'processing';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
}

<?php

namespace App\Enums;

enum PaymentTransactionStatus: string
{
    case INITIATED = 'initiated';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case REFUNDED = 'refunded';
}

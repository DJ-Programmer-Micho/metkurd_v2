<?php

namespace App\Enums;

enum PaymentTransactionType: string
{
    case INITIATE = 'initiate';
    case CHARGE = 'charge';
    case STATUS_SYNC = 'status_sync';
    case WEBHOOK = 'webhook';
    case CANCEL = 'cancel';
    case REFUND = 'refund';
    case REGISTER = 'register';
    case SCHEDULE = 'schedule';
}

<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case FAKE = 'fake';
    case FIB = 'fib';
    case AREEBA = 'areeba';
    case FIB_QR = 'fib_qr';
    case FIB_REDIRECT = 'fib_redirect';
    case CARD_REDIRECT = 'card_redirect';
}

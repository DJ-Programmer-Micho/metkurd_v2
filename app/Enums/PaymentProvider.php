<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case FAKE = 'fake';
    case FIB = 'fib';
    case AREEBA = 'areeba';
}

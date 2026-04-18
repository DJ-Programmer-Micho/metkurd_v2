<?php

namespace App\Domain\Payments\Enums;

enum PaymentMode: string
{
    case RECURRING = 'recurring';
    case ONE_TIME = 'one_time';
}

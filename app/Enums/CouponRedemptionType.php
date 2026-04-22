<?php

namespace App\Enums;

enum CouponRedemptionType: string
{
    case CHECKOUT = 'checkout';
    case CYCLE = 'cycle';
}

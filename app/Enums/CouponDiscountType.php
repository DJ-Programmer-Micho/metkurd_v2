<?php

namespace App\Enums;

enum CouponDiscountType: string
{
    case PERCENT = 'percent';
    case FIXED = 'fixed';
}

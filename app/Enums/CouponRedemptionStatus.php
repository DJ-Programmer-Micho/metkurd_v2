<?php

namespace App\Enums;

enum CouponRedemptionStatus: string
{
    case RESERVED = 'reserved';
    case APPLIED = 'applied';
    case CONSUMED = 'consumed';
    case RELEASED = 'released';
}

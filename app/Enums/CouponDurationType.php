<?php

namespace App\Enums;

enum CouponDurationType: string
{
    case ONCE = 'once';
    case FIRST_CYCLE = 'first_cycle';
    case FIRST_N_CYCLES = 'first_n_cycles';
    case FOREVER = 'forever';
}

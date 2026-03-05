<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditWallet extends Model
{
    protected $fillable = ['customer_id','balance_credits','lifetime_earned','lifetime_spent','cycle_started_on','cycle_ends_on'];
    protected $casts = ['cycle_started_on'=>'date','cycle_ends_on'=>'date'];
}


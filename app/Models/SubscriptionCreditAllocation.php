<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionCreditAllocation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cycle_started_at' => 'datetime',
        'paid_through' => 'datetime',
        'applied_at' => 'datetime',
    ];
}

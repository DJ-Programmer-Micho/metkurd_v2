<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerServiceSubscription extends Model
{
    protected $fillable = [
        'customer_id','service_plan_id','status',
        'starts_at','ends_at','cycle_started_on','cycle_ends_on',
        'previous_service_plan_id','upgraded_at','meta'
    ];
    protected $casts = ['starts_at'=>'datetime','ends_at'=>'datetime','upgraded_at'=>'datetime','meta'=>'array'];
}


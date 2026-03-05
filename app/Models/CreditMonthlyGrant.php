<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditMonthlyGrant extends Model
{
    protected $fillable = ['customer_id','service_plan_id','year_month','granted_credits','granted_at'];
    protected $casts = ['granted_at'=>'datetime'];
}


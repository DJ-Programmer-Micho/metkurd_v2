<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditOrder extends Model
{
    protected $fillable = ['customer_id','status','credits_amount','amount_usd','provider','provider_ref','meta'];
    protected $casts = ['meta'=>'array'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerStorageSubscription extends Model
{
    protected $fillable = ['customer_id','storage_plan_id','status','starts_at','ends_at','meta'];
    protected $casts = ['starts_at'=>'datetime','ends_at'=>'datetime','meta'=>'array'];
}

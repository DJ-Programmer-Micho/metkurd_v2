<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFile extends Model
{
    protected $fillable = ['customer_id','purpose','tool_code','disk','path','size_bytes','mime','checksum','status','deleted_at','meta'];
    protected $casts = ['deleted_at'=>'datetime','meta'=>'array'];
}


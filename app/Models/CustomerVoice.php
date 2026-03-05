<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerVoice extends Model
{
    protected $fillable = ['customer_id','voice_id','type','name','provider','storage_disk','storage_path','meta','is_active'];
    protected $casts = ['meta'=>'array','is_active'=>'boolean'];
}
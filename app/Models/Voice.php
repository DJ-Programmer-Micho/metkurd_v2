<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voice extends Model
{
    protected $fillable = ['code','name','is_public','is_active','sort_order','meta'];
    protected $casts = ['is_public'=>'boolean','is_active'=>'boolean','meta'=>'array'];
}

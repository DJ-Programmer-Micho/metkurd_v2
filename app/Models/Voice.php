<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voice extends Model
{
    protected $fillable = ['code','name','tool_code','model_code','is_public','is_active','meta'];
    protected $casts = ['is_public'=>'boolean','is_active'=>'boolean','meta'=>'array'];
}

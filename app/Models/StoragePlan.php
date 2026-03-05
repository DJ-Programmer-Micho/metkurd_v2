<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoragePlan extends Model
{
    protected $fillable = ['code','name','quota_mb','is_active','sort_order'];
    protected $casts = ['is_active' => 'boolean'];
}
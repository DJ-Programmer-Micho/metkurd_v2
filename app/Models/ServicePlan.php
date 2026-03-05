<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServicePlan extends Model
{
    protected $fillable = ['code','name','monthly_credits','is_active','sort_order','ui_features'];
    protected $casts = ['is_active' => 'boolean', 'ui_features' => 'array'];
}
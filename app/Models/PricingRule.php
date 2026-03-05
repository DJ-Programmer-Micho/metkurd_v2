<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    protected $fillable = ['tool_action_id','rule_type','priority','conditions','cost_credits','config','is_active'];
    protected $casts = ['conditions'=>'array','config'=>'array','is_active'=>'boolean'];
}

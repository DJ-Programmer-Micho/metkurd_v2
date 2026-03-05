<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPricingRule extends Model
{
    protected $fillable = ['customer_id','tool_action_id','rule_type','priority','conditions','cost_credits','starts_at','ends_at','is_active','meta'];
    protected $casts = ['conditions'=>'array','starts_at'=>'datetime','ends_at'=>'datetime','is_active'=>'boolean','meta'=>'array'];
}
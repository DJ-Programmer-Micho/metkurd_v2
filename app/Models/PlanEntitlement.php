<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanEntitlement extends Model
{
    protected $fillable = ['service_plan_id','tool_action_id','allowed','limits'];
    protected $casts = ['allowed'=>'boolean','limits'=>'array'];
}

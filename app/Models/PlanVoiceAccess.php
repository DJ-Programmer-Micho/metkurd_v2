<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanVoiceAccess extends Model
{
    protected $table = 'plan_voice_access';
    protected $fillable = ['service_plan_id','voice_id','allowed'];
    protected $casts = ['allowed'=>'boolean'];
}
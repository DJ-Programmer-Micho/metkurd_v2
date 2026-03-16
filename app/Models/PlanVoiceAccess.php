<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanVoiceAccess extends Model
{
    use HasFactory;

    protected $table = 'plan_voice_access';
    protected $fillable = ['service_plan_id','voice_id','is_public','is_active','sort_order','meta'];
    protected $casts = ['is_public'=>'boolean','is_active'=>'boolean','meta'=>'array'];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class, 'voice_id');
    }
}

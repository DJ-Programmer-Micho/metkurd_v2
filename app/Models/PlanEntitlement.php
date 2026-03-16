<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanEntitlement extends Model
{
    use HasFactory;

    protected $fillable = ['service_plan_id','tool_action_id','allowed','limits'];
    protected $casts = ['allowed'=>'boolean','limits'=>'array'];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }
}

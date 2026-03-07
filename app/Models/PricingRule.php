<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use HasFactory;

    protected $table = 'pricing_rules';

    protected $fillable = [
        'tool_action_id',
        'service_plan_id',
        'rule_scope',
        'rule_type',
        'priority',
        'metric_code',
        'unit_size',
        'credits_per_unit',
        'rounding_mode',
        'rounding_step',
        'minimum_credits',
        'conditions',
        'config',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'unit_size' => 'decimal:4',
        'credits_per_unit' => 'decimal:4',
        'rounding_step' => 'decimal:4',
        'minimum_credits' => 'integer',
        'conditions' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }
}

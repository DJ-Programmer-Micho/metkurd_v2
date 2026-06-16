<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPricingRule extends Model
{
    use HasFactory;

    protected $table = 'customer_pricing_rules';

    protected $fillable = [
        'customer_id',
        'tool_action_id',
        'pricing_channel',
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
        'starts_at',
        'ends_at',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'priority' => 'integer',
        'unit_size' => 'decimal:4',
        'credits_per_unit' => 'decimal:4',
        'rounding_step' => 'decimal:4',
        'minimum_credits' => 'integer',
        'conditions' => 'array',
        'config' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }
}

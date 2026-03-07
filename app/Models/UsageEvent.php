<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    use HasFactory;

    protected $table = 'usage_events';

    protected $fillable = [
        'customer_id',
        'tool_action_id',
        'source_type',
        'source_id',
        'status',
        'metric_code',
        'input_quantity',
        'billable_quantity',
        'unit_size',
        'unit_price_credits',
        'rounding_mode',
        'rounding_step',
        'total_credits',
        'pricing_rule_id',
        'customer_pricing_rule_id',
        'breakdown',
        'meta',
        'charged_at',
    ];

    protected $casts = [
        'input_quantity' => 'decimal:4',
        'billable_quantity' => 'decimal:4',
        'unit_size' => 'decimal:4',
        'unit_price_credits' => 'decimal:4',
        'rounding_step' => 'decimal:4',
        'total_credits' => 'integer',
        'breakdown' => 'array',
        'meta' => 'array',
        'charged_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class, 'pricing_rule_id');
    }

    public function customerPricingRule(): BelongsTo
    {
        return $this->belongsTo(CustomerPricingRule::class, 'customer_pricing_rule_id');
    }
}

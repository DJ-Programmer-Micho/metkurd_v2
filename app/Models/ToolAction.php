<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToolAction extends Model
{
    use HasFactory;

    protected $table = 'tool_actions';

    protected $fillable = [
        'tool_code',
        'action_code',
        'full_code',
        'name',
        'default_metric_code',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class, 'tool_action_id');
    }

    public function customerPricingRules(): HasMany
    {
        return $this->hasMany(CustomerPricingRule::class, 'tool_action_id');
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class, 'tool_action_id');
    }
}

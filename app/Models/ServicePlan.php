<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePlan extends Model
{
    use HasFactory;

    protected $table = 'service_plans';

    protected $fillable = [
        'code',
        'name',
        'billing_interval',
        'monthly_credits',
        'is_free',
        'is_active',
        'sort_order',
        'price_usd_monthly',
        'price_usd_yearly',
        'ui_features',
        'meta',
    ];

    protected $casts = [
        'monthly_credits' => 'integer',
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'price_usd_monthly' => 'decimal:2',
        'price_usd_yearly' => 'decimal:2',
        'ui_features' => 'array',
        'meta' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerServiceSubscription::class, 'service_plan_id');
    }

    public function previousSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerServiceSubscription::class, 'previous_service_plan_id');
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class, 'service_plan_id');
    }

    public function monthlyGrants(): HasMany
    {
        return $this->hasMany(CreditMonthlyGrant::class, 'service_plan_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerServiceSubscription extends Model
{
    use HasFactory;

    protected $table = 'customer_service_subscriptions';

    protected $fillable = [
        'customer_id',
        'service_plan_id',
        'previous_service_plan_id',
        'status',
        'source',
        'provider_ref',
        'starts_at',
        'ends_at',
        'canceled_at',
        'upgraded_at',
        'cycle_started_on',
        'cycle_ends_on',
        'next_renewal_on',
        'auto_renew',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'upgraded_at' => 'datetime',
        'cycle_started_on' => 'date',
        'cycle_ends_on' => 'date',
        'next_renewal_on' => 'date',
        'auto_renew' => 'boolean',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function previousServicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'previous_service_plan_id');
    }

    public function monthlyGrants(): HasMany
    {
        return $this->hasMany(CreditMonthlyGrant::class, 'subscription_id');
    }
}

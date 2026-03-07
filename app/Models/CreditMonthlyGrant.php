<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditMonthlyGrant extends Model
{
    use HasFactory;

    protected $table = 'credit_monthly_grants';

    protected $fillable = [
        'customer_id',
        'service_plan_id',
        'subscription_id',
        'year_month',
        'granted_credits',
        'granted_at',
        'meta',
    ];

    protected $casts = [
        'granted_credits' => 'integer',
        'granted_at' => 'datetime',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerServiceSubscription::class, 'subscription_id');
    }
}

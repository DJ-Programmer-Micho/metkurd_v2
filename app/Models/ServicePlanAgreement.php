<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePlanAgreement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'concurrent_jobs_limit' => 'integer',
        'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'app_monthly_credits' => 'integer', 'api_monthly_credits' => 'integer', 'agreed_amount_iqd' => 'integer',
    ];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerServiceSubscription::class, 'subscription_id');
    }
}

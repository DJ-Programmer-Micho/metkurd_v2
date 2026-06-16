<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageLog extends Model
{
    protected $fillable = [
        'customer_id',
        'api_key_id',
        'api_job_id',
        'endpoint',
        'method',
        'metric_code',
        'metric_quantity',
        'credits_charged',
        'status',
        'response_code',
        'ip_address',
        'user_agent',
        'idempotency_key',
        'request_hash',
        'meta',
    ];

    protected $casts = [
        'metric_quantity' => 'decimal:4',
        'credits_charged' => 'integer',
        'response_code' => 'integer',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(CustomerApiKey::class, 'api_key_id');
    }

    public function apiJob(): BelongsTo
    {
        return $this->belongsTo(ApiJob::class, 'api_job_id');
    }
}

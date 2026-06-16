<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'api_key_id',
        'ml_job_id',
        'tool_code',
        'tool_action',
        'engine',
        'status',
        'input_hash',
        'estimated_credits',
        'reserved_credits',
        'final_credits',
        'storage_mode',
        'error_code',
        'error_message',
        'meta',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'estimated_credits' => 'integer',
        'reserved_credits' => 'integer',
        'final_credits' => 'integer',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(CustomerApiKey::class, 'api_key_id');
    }

    public function mlJob(): BelongsTo
    {
        return $this->belongsTo(MlJob::class, 'ml_job_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(ApiUsageLog::class, 'api_job_id');
    }

    public function resultFiles(): HasMany
    {
        return $this->hasMany(ApiResultFile::class, 'api_job_id');
    }
}

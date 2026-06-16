<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCreditReservation extends Model
{
    protected $fillable = [
        'customer_id',
        'api_job_id',
        'amount',
        'status',
        'meta',
        'settled_at',
        'released_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'meta' => 'array',
        'settled_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function apiJob(): BelongsTo
    {
        return $this->belongsTo(ApiJob::class, 'api_job_id');
    }
}

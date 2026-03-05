<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerUsage extends Model
{
    protected $table = 'customer_usages'; // ✅ force singular table

    protected $fillable = [
        'customer_id',
        'storage_used_bytes',
        'jobs_total',
        'jobs_succeeded',
        'jobs_failed',
    ];

    protected $casts = [
        'storage_used_bytes' => 'integer',
        'jobs_total' => 'integer',
        'jobs_succeeded' => 'integer',
        'jobs_failed' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
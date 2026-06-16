<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditLedger extends Model
{
    protected $table = 'credit_ledgers';

    const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'wallet_type',
        'type',
        'source_type',
        'source_id',
        'direction',
        'amount',
        'bucket',
        'credits_delta',
        'balance_before',
        'balance_after',
        'subscription_balance_after',
        'addon_balance_after',
        'related_type',
        'related_id',
        'reference_code',
        'tool_code',
        'tool_action',
        'metric_code',
        'metric_quantity',
        'api_key_id',
        'api_job_id',
        'ml_job_id',
        'meta',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_before' => 'integer',
        'meta' => 'array',
        'metric_quantity' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

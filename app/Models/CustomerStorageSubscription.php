<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerStorageSubscription extends Model
{
    protected $fillable = [
        'customer_id',
        'storage_plan_id',
        'status',
        'price_iqd_snapshot',
        'display_currency_code',
        'display_exchange_rate',
        'display_amount_raw',
        'display_amount_rounded',
        'display_rounding_step',
        'display_rounding_mode',
        'display_country_code',
        'starts_at',
        'ends_at',
        'meta',
    ];

    protected $casts = [
        'price_iqd_snapshot' => 'decimal:0',
        'display_exchange_rate' => 'decimal:8',
        'display_amount_raw' => 'decimal:8',
        'display_amount_rounded' => 'decimal:4',
        'display_rounding_step' => 'decimal:4',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'meta' => 'array',
    ];

    public function plan()
    {
        return $this->belongsTo(StoragePlan::class, 'storage_plan_id');
    }

    public function storagePlan()
    {
        return $this->belongsTo(StoragePlan::class, 'storage_plan_id');
    }
}

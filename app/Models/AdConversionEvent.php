<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdConversionEvent extends Model
{
    protected $fillable = [
        'event_name',
        'customer_id',
        'payment_id',
        'transaction_id',
        'dedupe_key',
        'payload',
        'fired_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'payment_id' => 'integer',
        'payload' => 'array',
        'fired_at' => 'datetime',
    ];
}

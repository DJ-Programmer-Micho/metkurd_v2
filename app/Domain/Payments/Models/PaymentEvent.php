<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_id',
        'provider',
        'event_type',
        'source',
        'event_key',
        'local_reference',
        'fib_payment_id',
        'before_status',
        'after_status',
        'response_code',
        'payload',
        'meta',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}

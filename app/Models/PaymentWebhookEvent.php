<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_key',
        'payment_intent_id',
        'merchant_transaction_id',
        'provider_payment_id',
        'provider_transaction_id',
        'provider_purchase_id',
        'event_type',
        'event_status',
        'signature_valid',
        'processing_status',
        'received_at',
        'processed_at',
        'response_code',
        'headers',
        'payload',
        'normalized_payload',
        'meta',
        'error_message',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'response_code' => 'integer',
        'headers' => 'array',
        'payload' => 'array',
        'normalized_payload' => 'array',
        'meta' => 'array',
    ];

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}

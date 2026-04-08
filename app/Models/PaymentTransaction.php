<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'payment_intent_id',
        'parent_transaction_id',
        'provider',
        'transaction_type',
        'status',
        'status_reason',
        'merchant_transaction_id',
        'provider_transaction_id',
        'provider_payment_id',
        'provider_purchase_id',
        'provider_reference',
        'amount_iqd',
        'gross_amount_iqd',
        'surcharge_amount_iqd',
        'provider_fee_amount_iqd',
        'net_amount_iqd',
        'currency',
        'card_origin',
        'processed_at',
        'failed_at',
        'request_payload',
        'response_payload',
        'normalized_payload',
        'meta',
    ];

    protected $casts = [
        'amount_iqd' => 'decimal:0',
        'gross_amount_iqd' => 'decimal:0',
        'surcharge_amount_iqd' => 'decimal:0',
        'provider_fee_amount_iqd' => 'decimal:0',
        'net_amount_iqd' => 'decimal:0',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'normalized_payload' => 'array',
        'meta' => 'array',
    ];

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    public function childTransactions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transaction_id');
    }
}

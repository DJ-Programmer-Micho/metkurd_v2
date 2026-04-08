<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPaymentMethod extends Model
{
    protected $fillable = [
        'customer_id',
        'provider',
        'provider_customer_ref',
        'provider_method_ref',
        'payment_method_type',
        'brand',
        'masked_pan',
        'last_four',
        'expiry_month',
        'expiry_year',
        'card_origin',
        'token_expires_at',
        'reusable_for_recurring',
        'is_default',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'expiry_month' => 'integer',
        'expiry_year' => 'integer',
        'token_expires_at' => 'datetime',
        'reusable_for_recurring' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'customer_payment_method_id');
    }
}

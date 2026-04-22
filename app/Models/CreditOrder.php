<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditOrder extends Model
{
    use HasFactory;

    protected $table = 'credit_orders';

    protected $fillable = [
        'customer_id',
        'coupon_id',
        'coupon_code',
        'payment_intent_id',
        'payment_id',
        'order_type',
        'source_type',
        'service_plan_id',
        'credit_product_id',
        'status',
        'status_reason',
        'credits_amount',
        'amount_usd',
        'currency',
        'base_currency_code',
        'base_amount_iqd',
        'original_amount_iqd',
        'discount_amount_iqd',
        'discounted_amount_iqd',
        'gross_amount_iqd',
        'surcharge_amount_iqd',
        'provider_fee_amount_iqd',
        'net_amount_iqd',
        'fee_currency_code',
        'display_currency_code',
        'display_exchange_rate',
        'display_amount_raw',
        'display_amount_rounded',
        'display_rounding_step',
        'display_rounding_mode',
        'display_country_code',
        'provider',
        'payment_method',
        'provider_ref',
        'merchant_transaction_id',
        'provider_transaction_id',
        'paid_at',
        'failed_at',
        'canceled_at',
        'refunded_at',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'coupon_id' => 'integer',
        'amount_usd' => 'decimal:2',
        'base_amount_iqd' => 'decimal:0',
        'original_amount_iqd' => 'decimal:0',
        'discount_amount_iqd' => 'decimal:0',
        'discounted_amount_iqd' => 'decimal:0',
        'gross_amount_iqd' => 'decimal:0',
        'surcharge_amount_iqd' => 'decimal:0',
        'provider_fee_amount_iqd' => 'decimal:0',
        'net_amount_iqd' => 'decimal:0',
        'display_exchange_rate' => 'decimal:8',
        'display_amount_raw' => 'decimal:8',
        'display_amount_rounded' => 'decimal:4',
        'display_rounding_step' => 'decimal:4',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Payments\Models\Payment::class, 'payment_id');
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function creditProduct(): BelongsTo
    {
        return $this->belongsTo(CreditProduct::class, 'credit_product_id');
    }

    public function hasLocalizedDisplayAmount(): bool
    {
        $currencyCode = strtoupper(trim((string) ($this->display_currency_code ?? '')));

        return $currencyCode !== '' && $currencyCode !== 'IQD' && $this->display_amount_rounded !== null;
    }
}

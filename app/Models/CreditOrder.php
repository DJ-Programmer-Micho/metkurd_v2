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
        'order_type',
        'source_type',
        'service_plan_id',
        'credit_product_id',
        'status',
        'credits_amount',
        'amount_usd',
        'currency',
        'base_currency_code',
        'base_amount_iqd',
        'display_currency_code',
        'display_exchange_rate',
        'display_amount_raw',
        'display_amount_rounded',
        'display_rounding_step',
        'display_rounding_mode',
        'display_country_code',
        'provider',
        'provider_ref',
        'paid_at',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'amount_usd' => 'decimal:2',
        'base_amount_iqd' => 'decimal:0',
        'display_exchange_rate' => 'decimal:8',
        'display_amount_raw' => 'decimal:8',
        'display_amount_rounded' => 'decimal:4',
        'display_rounding_step' => 'decimal:4',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
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

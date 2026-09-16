<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerStorageSubscription extends Model
{
    public function scopeEffectiveAt(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        app(\App\Services\Billing\BillingSubscriptionAuthority::class)->apply($query);
        $table = $query->getModel()->getTable();

        return $query->where($table.'.status', 'active')->whereNull($table.'.meta->superseded_at')
            ->where(fn ($q) => $q->whereNull($table.'.starts_at')->orWhere($table.'.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull($table.'.ends_at')->orWhere($table.'.ends_at', '>=', now()));
    }

    protected $fillable = [
        'customer_id',
        'payment_id',
        'coupon_id',
        'storage_plan_id',
        'status',
        'source',
        'provider_ref',
        'price_iqd_snapshot',
        'original_price_iqd_snapshot',
        'discount_cycles_consumed',
        'display_currency_code',
        'display_exchange_rate',
        'display_amount_raw',
        'display_amount_rounded',
        'display_rounding_step',
        'display_rounding_mode',
        'display_country_code',
        'starts_at',
        'ends_at',
        'canceled_at',
        'cycle_started_on',
        'cycle_ends_on',
        'next_renewal_on',
        'auto_renew',
        'customer_payment_method_id',
        'renewal_strategy',
        'meta',
    ];

    protected $casts = [
        'price_iqd_snapshot' => 'decimal:0',
        'coupon_id' => 'integer',
        'original_price_iqd_snapshot' => 'decimal:0',
        'discount_cycles_consumed' => 'integer',
        'display_exchange_rate' => 'decimal:8',
        'display_amount_raw' => 'decimal:8',
        'display_amount_rounded' => 'decimal:4',
        'display_rounding_step' => 'decimal:4',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'cycle_started_on' => 'date',
        'cycle_ends_on' => 'date',
        'next_renewal_on' => 'date',
        'auto_renew' => 'boolean',
        'customer_payment_method_id' => 'integer',
        'meta' => 'array',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StoragePlan::class, 'storage_plan_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Payments\Models\Payment::class, 'payment_id');
    }

    public function storagePlan(): BelongsTo
    {
        return $this->belongsTo(StoragePlan::class, 'storage_plan_id');
    }

    public function customerPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'customer_payment_method_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerServiceSubscription extends Model
{
    use HasFactory;

    /** Eligibility shared by current-plan reads; historical relationships stay unfiltered. */
    public function scopeEffectiveAt(\Illuminate\Database\Eloquent\Builder $query, ?\Carbon\CarbonInterface $at = null): \Illuminate\Database\Eloquent\Builder
    {
        $at ??= now();
        $table = $query->getModel()->getTable();
        app(\App\Services\Billing\BillingSubscriptionAuthority::class)->apply($query, $at);

        return $query->where($table.'.status', 'active')
            ->whereNull($table.'.meta->superseded_at')
            ->where(fn ($q) => $q->whereNull($table.'.starts_at')->orWhere($table.'.starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull($table.'.ends_at')->orWhere($table.'.ends_at', '>=', $at))
            ->where(fn ($q) => $q->whereNull($table.'.source')
                ->orWhere($table.'.source', '!=', \App\Services\Billing\ServiceAgreementLifecycle::SOURCE)
                ->orWhere($table.'.ends_at', '>', $at));
    }

    public function scopeExcludingComplimentary(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('source')->orWhereNotIn('source', ['admin_manual', 'admin_manual_grant', 'internal_non_revenue']))
            ->where(fn ($q) => $q->whereNull('meta->revenue_excluded')->orWhereNotIn('meta->revenue_excluded', [true, 1, '1', 'true']))
            ->where(fn ($q) => $q->whereNull('meta->revenue_record')->orWhereNotIn('meta->revenue_record', [false, 0, '0', 'false']))
            ->where(fn ($q) => $q->whereNull('meta->billing_source')->orWhereNotIn('meta->billing_source', ['admin_manual_grant', 'internal_non_revenue']));
    }

    protected $table = 'customer_service_subscriptions';

    protected $fillable = [
        'customer_id',
        'payment_id',
        'coupon_id',
        'service_plan_id',
        'previous_service_plan_id',
        'status',
        'source',
        'provider_ref',
        'starts_at',
        'ends_at',
        'canceled_at',
        'upgraded_at',
        'cycle_started_on',
        'cycle_ends_on',
        'next_renewal_on',
        'auto_renew',
        'customer_payment_method_id',
        'renewal_strategy',
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
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'upgraded_at' => 'datetime',
        'cycle_started_on' => 'date',
        'cycle_ends_on' => 'date',
        'next_renewal_on' => 'date',
        'auto_renew' => 'boolean',
        'customer_payment_method_id' => 'integer',
        'price_iqd_snapshot' => 'decimal:0',
        'coupon_id' => 'integer',
        'original_price_iqd_snapshot' => 'decimal:0',
        'discount_cycles_consumed' => 'integer',
        'display_exchange_rate' => 'decimal:8',
        'display_amount_raw' => 'decimal:8',
        'display_amount_rounded' => 'decimal:4',
        'display_rounding_step' => 'decimal:4',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Payments\Models\Payment::class, 'payment_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function previousServicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'previous_service_plan_id');
    }

    public function customerPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'customer_payment_method_id');
    }

    public function monthlyGrants(): HasMany
    {
        return $this->hasMany(CreditMonthlyGrant::class, 'subscription_id');
    }
}

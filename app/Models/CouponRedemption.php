<?php

namespace App\Models;

use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Enums\CouponRedemptionStatus;
use App\Enums\CouponRedemptionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id',
        'customer_id',
        'payment_id',
        'purchase_type',
        'purchasable_type',
        'purchasable_id',
        'item_code',
        'billing_cycle',
        'cycle_index',
        'redemption_type',
        'status',
        'coupon_code',
        'original_amount_iqd',
        'discount_amount_iqd',
        'final_amount_iqd',
        'discount_snapshot',
        'metadata',
    ];

    protected $casts = [
        'purchase_type' => PurchaseType::class,
        'cycle_index' => 'integer',
        'redemption_type' => CouponRedemptionType::class,
        'status' => CouponRedemptionStatus::class,
        'original_amount_iqd' => 'decimal:0',
        'discount_amount_iqd' => 'decimal:0',
        'final_amount_iqd' => 'decimal:0',
        'discount_snapshot' => 'array',
        'metadata' => 'array',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}

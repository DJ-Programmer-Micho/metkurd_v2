<?php

namespace App\Models;

use App\Enums\CouponDiscountType;
use App\Enums\CouponDurationType;
use App\Enums\CouponTargetType;
use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_public',
        'is_stackable',
        'discount_type',
        'discount_value',
        'target_type',
        'supported_payment_methods',
        'applies_to_codes',
        'applies_to_billing_cycles',
        'first_time_subscribers_only',
        'duration_type',
        'duration_cycles',
        'max_total_uses',
        'max_uses_per_customer',
        'used_count',
        'starts_at',
        'ends_at',
        'minimum_amount_iqd',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_stackable' => 'boolean',
        'discount_type' => CouponDiscountType::class,
        'discount_value' => 'decimal:4',
        'target_type' => CouponTargetType::class,
        'supported_payment_methods' => 'array',
        'applies_to_codes' => 'array',
        'applies_to_billing_cycles' => 'array',
        'first_time_subscribers_only' => 'boolean',
        'duration_type' => CouponDurationType::class,
        'duration_cycles' => 'integer',
        'max_total_uses' => 'integer',
        'max_uses_per_customer' => 'integer',
        'used_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'minimum_amount_iqd' => 'decimal:0',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Coupon $coupon): void {
            $coupon->code = strtoupper(trim((string) $coupon->code));
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class, 'coupon_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'coupon_id');
    }
}

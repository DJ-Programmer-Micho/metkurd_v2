<?php

namespace App\Models;

use App\Domain\Payments\Enums\PaymentMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoragePlan extends Model
{
    use HasFactory;

    protected $table = 'storage_plans';

    protected $fillable = ['code', 'name', 'quota_mb', 'price_usd', 'price_iqd', 'payment_mode', 'billing_intervals', 'is_active', 'sort_order'];

    protected $casts = [
        'quota_mb' => 'integer',
        'price_usd' => 'decimal:2',
        'price_iqd' => 'decimal:0',
        'billing_intervals' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerStorageSubscription::class, 'storage_plan_id');
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->subscriptions()->where('status', 'active');
    }

    public function priceUsdAmount(): float
    {
        return (float) ($this->price_usd ?? 0);
    }

    public function priceIqdAmount(): int
    {
        if ($this->price_iqd !== null) {
            return (int) round((float) $this->price_iqd);
        }

        return app(\App\Services\Billing\BillingCurrencyService::class)
            ->legacyUsdAmountToIqd($this->priceUsdAmount());
    }

    public function checkoutPaymentMode(): PaymentMode
    {
        return PaymentMode::fromValue($this->payment_mode, PaymentMode::RECURRING);
    }

    public function checkoutPaymentModeValue(): string
    {
        return $this->checkoutPaymentMode()->value;
    }

    public function billingIntervals(): array
    {
        $allowed = ['monthly', 'yearly'];
        $configured = collect(is_array($this->billing_intervals) ? $this->billing_intervals : [])
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        $legacy = strtolower(trim((string) ($this->billing_interval ?? 'monthly')));

        if (in_array($legacy, $allowed, true)) {
            return [$legacy];
        }

        return ['monthly'];
    }

    public function supportsBillingInterval(string $cycle): bool
    {
        $cycle = strtolower(trim($cycle));
        $normalized = $cycle === 'hourly' ? 'monthly' : $cycle;

        return in_array($normalized, $this->billingIntervals(), true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoragePlan extends Model
{
    use HasFactory;

    protected $table = 'storage_plans';

    protected $fillable = ['code', 'name', 'quota_mb', 'price_usd', 'price_iqd', 'is_active', 'sort_order'];

    protected $casts = [
        'quota_mb' => 'integer',
        'price_usd' => 'decimal:2',
        'price_iqd' => 'decimal:0',
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
}

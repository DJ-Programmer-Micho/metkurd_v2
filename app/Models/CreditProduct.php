<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditProduct extends Model
{
    use HasFactory;

    protected $table = 'credit_products';

    protected $fillable = [
        'code',
        'name',
        'credits_amount',
        'price_usd',
        'price_iqd',
        'is_active',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'price_usd' => 'decimal:2',
        'price_iqd' => 'decimal:0',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(CreditOrder::class, 'credit_product_id');
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

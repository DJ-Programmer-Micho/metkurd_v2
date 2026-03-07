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
        'is_active',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'price_usd' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(CreditOrder::class, 'credit_product_id');
    }
}

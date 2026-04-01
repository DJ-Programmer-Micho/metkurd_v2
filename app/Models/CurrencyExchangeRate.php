<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyExchangeRate extends Model
{
    use HasFactory;

    protected $table = 'currency_exchange_rates';

    protected $fillable = [
        'base_currency_code',
        'quote_currency_code',
        'rate',
        'source',
        'effective_at',
        'expires_at',
        'is_current',
        'meta',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'effective_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_current' => 'boolean',
        'meta' => 'array',
    ];

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_code', 'code');
    }

    public function quoteCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'quote_currency_code', 'code');
    }
}

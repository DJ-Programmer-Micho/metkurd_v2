<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasFactory;

    protected $table = 'currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'rounding_step',
        'rounding_mode',
        'is_base',
        'is_active',
        'locale_hint',
        'meta',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'rounding_step' => 'decimal:4',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function baseExchangeRates(): HasMany
    {
        return $this->hasMany(CurrencyExchangeRate::class, 'base_currency_code', 'code');
    }

    public function quoteExchangeRates(): HasMany
    {
        return $this->hasMany(CurrencyExchangeRate::class, 'quote_currency_code', 'code');
    }

    public function countryMappings(): HasMany
    {
        return $this->hasMany(CountryCurrencyMap::class, 'currency_code', 'code');
    }
}

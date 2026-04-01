<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerProfile extends Model
{
    protected $table = 'customer_profiles';
    protected $fillable = [
        'customer_id',
        'first_name',
        'last_name',
        'job_title',
        'brand_name',
        'country',
        'display_currency_code',
        'city',
        'address',
        'zip_code',
        'phone_number',
        'avatar',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function displayCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'display_currency_code', 'code');
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) return null;
        return Str::startsWith($this->avatar, ['http://','https://'])
            ? $this->avatar
            : app('cloudfront').$this->avatar;
    }
}

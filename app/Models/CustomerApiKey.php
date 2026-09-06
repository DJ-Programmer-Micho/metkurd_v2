<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerApiKey extends Model
{
    protected $hidden = ['key_hash'];

    protected $fillable = [
        'customer_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes',
        'status',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ApiJob::class, 'api_key_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(ApiUsageLog::class, 'api_key_id');
    }

    public function isActive(): bool
    {
        return (string) $this->status === 'active' && $this->revoked_at === null;
    }
}

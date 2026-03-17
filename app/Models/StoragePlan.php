<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoragePlan extends Model
{
    use HasFactory;

    protected $table = 'storage_plans';

    protected $fillable = ['code', 'name', 'quota_mb', 'price_usd', 'is_active', 'sort_order'];

    protected $casts = [
        'quota_mb' => 'integer',
        'price_usd' => 'decimal:2',
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
}

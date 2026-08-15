<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Voice extends Model
{
    protected $fillable = ['code', 'name', 'is_public', 'is_active', 'sort_order', 'meta'];

    protected $casts = ['is_public' => 'boolean', 'is_active' => 'boolean', 'meta' => 'array'];

    public function planAccesses(): HasMany
    {
        return $this->hasMany(PlanVoiceAccess::class, 'voice_id');
    }

    public function activePlanAccesses(): HasMany
    {
        return $this->planAccesses()->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::bumpCatalogCacheVersion());
        static::deleted(fn () => static::bumpCatalogCacheVersion());
    }

    protected static function bumpCatalogCacheVersion(): void
    {
        Cache::add('omni-speaker-catalog:version', 1);
        Cache::increment('omni-speaker-catalog:version');
    }
}

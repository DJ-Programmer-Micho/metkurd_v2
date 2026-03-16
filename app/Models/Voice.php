<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voice extends Model
{
    protected $fillable = ['code','name','is_public','is_active','sort_order','meta'];
    protected $casts = ['is_public'=>'boolean','is_active'=>'boolean','meta'=>'array'];

    public function planAccesses(): HasMany
    {
        return $this->hasMany(PlanVoiceAccess::class, 'voice_id');
    }

    public function activePlanAccesses(): HasMany
    {
        return $this->planAccesses()->where('is_active', true);
    }
}

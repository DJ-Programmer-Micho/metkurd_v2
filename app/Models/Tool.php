<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tool extends Model
{
    protected $fillable = ['code','name','is_active','sort_order','meta'];
    protected $casts = ['is_active'=>'boolean','meta'=>'array'];

    public function actions(): HasMany
    {
        return $this->hasMany(ToolAction::class, 'tool_code', 'code');
    }

    public function activeActions(): HasMany
    {
        return $this->actions()->where('is_active', true);
    }
}

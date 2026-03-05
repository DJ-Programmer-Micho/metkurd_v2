<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tool extends Model
{
    protected $fillable = ['code','name','is_active','sort_order','meta'];
    protected $casts = ['is_active'=>'boolean','meta'=>'array'];

    public function actions() { return $this->hasMany(ToolAction::class); }
}

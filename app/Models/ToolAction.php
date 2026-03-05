<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToolAction extends Model
{
    protected $fillable = ['tool_id','code','full_code','name','is_active','meta'];
    protected $casts = ['is_active'=>'boolean','meta'=>'array'];

    public function tool() { return $this->belongsTo(Tool::class); }
}

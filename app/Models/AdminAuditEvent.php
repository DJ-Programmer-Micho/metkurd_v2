<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['before_state' => 'array', 'requested' => 'array', 'after_state' => 'array'];
}

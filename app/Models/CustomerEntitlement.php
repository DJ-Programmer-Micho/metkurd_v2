<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerEntitlement extends Model
{
    protected $fillable = ['customer_id', 'tool_action_id', 'entitlement_channel', 'allowed', 'limits', 'starts_at', 'ends_at', 'meta'];

    protected $casts = ['allowed' => 'boolean', 'limits' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'meta' => 'array'];
}

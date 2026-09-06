<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['requested' => 'array', 'result' => 'array', 'completed_at' => 'datetime'];
}

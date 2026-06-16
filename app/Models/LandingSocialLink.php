<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingSocialLink extends Model
{
    protected $fillable = [
        'platform',
        'url',
        'icon_class',
        'is_active',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}

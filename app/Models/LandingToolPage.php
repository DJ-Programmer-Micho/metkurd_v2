<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingToolPage extends Model
{
    protected $fillable = [
        'slug',
        'icon_class',
        'square_image_path',
        'hero_image_path',
        'card_image_path',
        'demo_type',
        'demo_config',
        'is_active',
        'sort_order',
        'content',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'demo_config' => 'array',
        'content' => 'array',
    ];
}

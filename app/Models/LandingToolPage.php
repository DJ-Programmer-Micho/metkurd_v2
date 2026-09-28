<?php

namespace App\Models;

use App\Support\Landing\PublicProductCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LandingToolPage extends Model
{
    public function publicVisibilityStatus(): string
    {
        return app(PublicProductCatalog::class)->visibilityStatus($this);
    }

    public function scopeWithPublicStatus(Builder $query, string $status): Builder
    {
        $catalog = app(PublicProductCatalog::class);

        return match ($status) {
            'active' => $query->where('is_active', true)->whereIn('slug', $catalog->publicFamilySlugs()),
            'inactive' => $query->whereIn('slug', $catalog->currentFamilySlugs())
                ->where(fn (Builder $q) => $q->where('is_active', false)->orWhereNotIn('slug', $catalog->publicFamilySlugs())),
            'legacy' => $query->whereNotIn('slug', $catalog->currentFamilySlugs()),
            default => $query,
        };
    }

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

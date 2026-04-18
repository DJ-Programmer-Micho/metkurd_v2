<?php

namespace App\Support;

class AvatarFallbackUrl
{
    /**
     * @var array<int, string>
     */
    protected const LOCAL_ASSET_CANDIDATES = [
        'admin/images/users/user-dummy-img.jpg',
        'admin/images/users/avatar-1.jpg',
        'admin/images/demos/default.png',
    ];

    public function customer(): string
    {
        foreach (self::LOCAL_ASSET_CANDIDATES as $path) {
            if (is_file(public_path($path))) {
                return asset($path);
            }
        }

        return $this->inlinePlaceholder();
    }

    protected function inlinePlaceholder(): string
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160" fill="none">
  <rect width="160" height="160" rx="80" fill="#D9E2EC"/>
  <circle cx="80" cy="60" r="28" fill="#829AB1"/>
  <path d="M34 134c7-26 26-40 46-40s39 14 46 40" fill="#829AB1"/>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
}

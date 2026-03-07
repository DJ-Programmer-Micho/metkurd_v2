<?php

namespace App\Support;

use Illuminate\Support\Str;

class CustomerFolder
{
    public static function make(int $customerId, ?string $first, ?string $last, ?string $username = null): string
    {
        $name = trim(($first ?? '') . ' ' . ($last ?? ''));
        $slug = Str::slug($name, '_');

        if ($slug === '') {
            $slug = Str::slug($username ?: 'customer', '_');
        }

        return $customerId . '_' . $slug;
    }
}

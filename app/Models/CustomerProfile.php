<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerProfile extends Model
{
    protected $table = 'customer_profiles';

    protected $fillable = [
        'customer_id',
        'first_name',
        'last_name',
        'job_title',
        'brand_name',
        'country',
        'display_currency_code',
        'city',
        'address',
        'zip_code',
        'phone_number',
        'avatar',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function displayCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'display_currency_code', 'code');
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $avatar = trim((string) ($this->avatar ?? ''));

        if ($avatar === '') {
            return null;
        }

        if (Str::startsWith($avatar, ['http://', 'https://', 'data:'])) {
            return $avatar;
        }

        if (Str::startsWith($avatar, ['/storage/', 'storage/'])) {
            return url('/'.ltrim($avatar, '/'));
        }

        $normalized = ltrim($avatar, '/');

        if ($normalized !== '' && Storage::disk('public')->exists($normalized)) {
            return Storage::disk('public')->url($normalized);
        }

        return $this->s3AvatarUrl($normalized);
    }

    protected function s3AvatarUrl(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return '';
        }

        $configuredUrl = trim((string) config('filesystems.disks.s3.url'));

        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/').'/'.$path;
        }

        try {
            return Storage::disk('s3')->url($path);
        } catch (\Throwable) {
            return rtrim((string) app('cloudfront'), '/').'/'.$path;
        }
    }
}

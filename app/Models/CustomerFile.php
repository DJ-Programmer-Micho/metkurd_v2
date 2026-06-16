<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFile extends Model
{
    protected $fillable = [
        'customer_id',
        'purpose',
        'tool_code',
        'disk',
        'path',
        'size_bytes',
        'mime',
        'checksum',
        'status',
        'retention_mode',
        'expires_at',
        'deleted_at',
        'delete_reason',
        'source_type',
        'source_id',
        'counts_toward_quota',
        'meta',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
        'counts_toward_quota' => 'boolean',
        'meta' => 'array',
    ];
}

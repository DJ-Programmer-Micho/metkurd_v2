<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiResultFile extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'api_job_id',
        'storage_file_id',
        'result_kind',
        'deleted_at',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function apiJob(): BelongsTo
    {
        return $this->belongsTo(ApiJob::class, 'api_job_id');
    }

    public function storageFile(): BelongsTo
    {
        return $this->belongsTo(CustomerFile::class, 'storage_file_id');
    }
}

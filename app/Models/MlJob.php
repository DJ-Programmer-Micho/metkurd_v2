<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MlJob extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'tool_id',
        'tool_action_id',
        'status',
        'input',
        'output',
        'error',
        'credits_charged',
        'storage_in_bytes',
        'storage_out_bytes',
        'provider',
        'provider_job_id',
        'provider_cost_usd',
        'cold_start_ms',
        'runtime_ms',
        'started_at',
        'finished_at',
        'job_kind',
        'execution_scope',
        'locked_by_session_id',
        'locked_by_fingerprint',
        'lock_expires_at',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'error' => 'array',
        'provider_cost_usd' => 'decimal:6',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'lock_expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class, 'tool_id');
    }

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running', 'saving'], true);
    }

    public function hasLiveLock(): bool
    {
        return $this->lock_expires_at instanceof Carbon
            && $this->lock_expires_at->isFuture()
            && $this->isActive();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'running', 'saving']);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('job_kind', $kind);
    }

    public function scopeLiveLocked(Builder $query): Builder
    {
        return $query
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '>', now())
            ->whereIn('status', ['queued', 'running', 'saving']);
    }
}
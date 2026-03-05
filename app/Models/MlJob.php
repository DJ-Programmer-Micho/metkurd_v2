<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlJob extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','customer_id','tool_id','tool_action_id','status',
        'input','output','error',
        'credits_charged','storage_in_bytes','storage_out_bytes',
        'provider','provider_job_id','provider_cost_usd',
        'cold_start_ms','runtime_ms','started_at','finished_at',
    ];

    protected $casts = [
        'input'=>'array','output'=>'array','error'=>'array',
        'provider_cost_usd'=>'decimal:6',
        'started_at'=>'datetime','finished_at'=>'datetime',
    ];
}

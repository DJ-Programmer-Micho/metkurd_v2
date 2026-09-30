<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderCoverageDisposition extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'renewal_stop_confirmed' => 'boolean',
        'customer_id' => 'integer', 'original_payment_id' => 'integer', 'subscription_id' => 'integer',
        'plan_id' => 'integer', 'evidence_event_id' => 'integer', 'admin_id' => 'integer'];

    public function operation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AdminOperation::class, 'operation_id');
    }
}

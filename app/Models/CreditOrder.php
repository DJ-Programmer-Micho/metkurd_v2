<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditOrder extends Model
{
    use HasFactory;

    protected $table = 'credit_orders';

    protected $fillable = [
        'customer_id',
        'order_type',
        'source_type',
        'service_plan_id',
        'credit_product_id',
        'status',
        'credits_amount',
        'amount_usd',
        'currency',
        'provider',
        'provider_ref',
        'paid_at',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'amount_usd' => 'decimal:2',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function creditProduct(): BelongsTo
    {
        return $this->belongsTo(CreditProduct::class, 'credit_product_id');
    }
}

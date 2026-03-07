<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'amount_usd' => 'decimal:2',
        'meta' => 'array',
    ];
}
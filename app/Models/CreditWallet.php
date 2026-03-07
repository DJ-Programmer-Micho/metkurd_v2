<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditWallet extends Model
{
    use HasFactory;

    protected $table = 'credit_wallets';

    protected $fillable = [
        'customer_id',
        'balance_credits',
        'subscription_balance_credits',
        'addon_balance_credits',
        'lifetime_earned',
        'lifetime_spent',
        'lifetime_refunded',
        'cycle_started_on',
        'cycle_ends_on',
        'current_cycle_key',
        'last_granted_at',
        'last_charged_at',
    ];

    protected $casts = [
        'balance_credits' => 'integer',
        'subscription_balance_credits' => 'integer',
        'addon_balance_credits' => 'integer',
        'lifetime_earned' => 'integer',
        'lifetime_spent' => 'integer',
        'lifetime_refunded' => 'integer',
        'cycle_started_on' => 'date',
        'cycle_ends_on' => 'date',
        'last_granted_at' => 'datetime',
        'last_charged_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}

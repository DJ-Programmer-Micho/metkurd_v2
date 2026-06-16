<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditWallet extends Model
{
    use HasFactory;

    public const TYPE_APP = 'app';

    public const TYPE_API = 'api';

    protected $table = 'credit_wallets';

    protected $fillable = [
        'customer_id',
        'wallet_type',
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
        'wallet_type' => 'string',
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

    protected $attributes = [
        'wallet_type' => self::TYPE_APP,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isAppWallet(): bool
    {
        return (string) $this->wallet_type === self::TYPE_APP;
    }

    public function isApiWallet(): bool
    {
        return (string) $this->wallet_type === self::TYPE_API;
    }

    public function syncCombinedBalance(): void
    {
        $this->balance_credits =
            (int) ($this->subscription_balance_credits ?? 0)
            + (int) ($this->addon_balance_credits ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultAttributes(
        int $customerId,
        string $walletType = self::TYPE_APP,
        ?CarbonInterface $anchor = null,
    ): array {
        $anchor ??= now();

        return [
            'customer_id' => $customerId,
            'wallet_type' => $walletType,
            'balance_credits' => 0,
            'subscription_balance_credits' => 0,
            'addon_balance_credits' => 0,
            'lifetime_earned' => 0,
            'lifetime_spent' => 0,
            'lifetime_refunded' => 0,
            'cycle_started_on' => $anchor->copy()->startOfMonth()->toDateString(),
            'cycle_ends_on' => $anchor->copy()->endOfMonth()->toDateString(),
            'current_cycle_key' => $anchor->format('Y-m'),
            'last_granted_at' => null,
            'last_charged_at' => null,
        ];
    }
}

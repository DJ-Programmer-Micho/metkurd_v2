<?php

namespace App\Models;

use App\Support\PricingRuleEngine;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'username','email','password','status',
        'email_verify','phone_verify','g_id','h_id',
        'email_otp_number','phone_otp_number','uid',
    ];

    protected $hidden = ['password','remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'email_verify' => 'boolean',
        'phone_verify' => 'boolean',
        'password' => 'hashed',
    ];

    // --------------------
    // Relations
    // --------------------
    public function profile()
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function usage()
    {
        return $this->hasOne(CustomerUsage::class);
    }

    public function wallet()
    {
        return $this->hasOne(CreditWallet::class);
    }

    public function creditLedger()
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function mlJobs()
    {
        return $this->hasMany(MlJob::class);
    }

    // Service subscription
    public function serviceSubscriptions()
    {
        return $this->hasMany(CustomerServiceSubscription::class);
    }

    public function activeServiceSubscription()
    {
        return $this->hasOne(CustomerServiceSubscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function servicePlan()
    {
        return $this->hasOneThrough(
            ServicePlan::class,
            CustomerServiceSubscription::class,
            'customer_id',
            'id',
            'id',
            'service_plan_id'
        )->where('customer_service_subscriptions.status', 'active');
    }

    public function serviceCode(): string
    {
        return $this->servicePlan()->first()?->code ?? 'free';
    }

    // Storage subscription
    public function storageSubscriptions()
    {
        return $this->hasMany(CustomerStorageSubscription::class);
    }

    public function activeStorageSubscription()
    {
        return $this->hasOne(CustomerStorageSubscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function storagePlan()
    {
        return $this->hasOneThrough(
            StoragePlan::class,
            CustomerStorageSubscription::class,
            'customer_id',
            'id',
            'id',
            'storage_plan_id'
        )->where('customer_storage_subscriptions.status', 'active');
    }

    public function storageQuotaMb(int $default = 512): int
    {
        return (int) ($this->storagePlan()->first()?->quota_mb ?? $default);
    }

    public function storageUsedBytes(): int
    {
        return (int) ($this->usage()->first()?->storage_used_bytes ?? 0);
    }

    // --------------------
    // Entitlement & Pricing helpers (fast path)
    // --------------------
    public function isAllowed(string $toolActionFullCode): bool
    {
        $action = ToolAction::where('full_code', $toolActionFullCode)->first();
        if (!$action) return false;

        // customer override window
        $override = CustomerEntitlement::where('customer_id', $this->id)
            ->where('tool_action_id', $action->id)
            ->where(function ($q) {
                $now = now();
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->first();

        if ($override && $override->allowed !== null) {
            return (bool) $override->allowed;
        }

        $plan = $this->servicePlan()->first();
        if (!$plan) return false;

        $ent = PlanEntitlement::where('service_plan_id', $plan->id)
            ->where('tool_action_id', $action->id)
            ->first();

        return $ent ? (bool) $ent->allowed : false;
    }

    public function priceCreditsFor(string $toolActionFullCode, array $context = []): int
    {
        $action = ToolAction::where('full_code', $toolActionFullCode)->first();
        if (!$action) return 0;

        $planCode = $this->serviceCode();
        $now = now();

        // 1) customer pricing overrides first
        $custRules = CustomerPricingRule::where('customer_id', $this->id)
            ->where('tool_action_id', $action->id)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('priority', 'asc') // customer overrides small number wins
            ->get();

        foreach ($custRules as $rule) {
            if (PricingRuleEngine::matches($rule->conditions, $planCode, $context)) {
                return PricingRuleEngine::resolveCost($rule->rule_type, $rule->cost_credits, $rule->config ?? null);
            }
        }

        // 2) plan pricing rules
        $rules = PricingRule::where('tool_action_id', $action->id)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($rules as $rule) {
            if (PricingRuleEngine::matches($rule->conditions, $planCode, $context)) {
                return PricingRuleEngine::resolveCost($rule->rule_type, $rule->cost_credits, $rule->config ?? null);
            }
        }

        return 0;
    }

    // --------------------
    // Defaults on register
    // --------------------
    protected static function booted()
    {
        static::created(function (Customer $customer) {
            $customer->usage()->firstOrCreate([], ['storage_used_bytes' => 0]);
            $customer->wallet()->firstOrCreate([], ['balance_credits' => 0]);

            // attach default plans
            $freeService = ServicePlan::where('code', 'free')->first();
            if ($freeService) {
                $customer->serviceSubscriptions()->create([
                    'service_plan_id' => $freeService->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'cycle_started_on' => now()->toDateString(),
                    'cycle_ends_on' => now()->addMonth()->toDateString(),
                ]);
            }

            $freeStorage = StoragePlan::where('code', 'free-512')->first();
            if ($freeStorage) {
                $customer->storageSubscriptions()->create([
                    'storage_plan_id' => $freeStorage->id,
                    'status' => 'active',
                    'starts_at' => now(),
                ]);
            }
        });
    }
}
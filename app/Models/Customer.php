<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Customer extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'status',
        'email_verify',
        'phone_verify',
        'g_id',
        'h_id',
        'email_otp_number',
        'phone_otp_number',
        'uid',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'email_verify' => 'boolean',
        'phone_verify' => 'boolean',
        'password' => 'hashed',
    ];

    // =========================================================
    // Relations
    // =========================================================

    public function profile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function usage(): HasOne
    {
        return $this->hasOne(CustomerUsage::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(CreditWallet::class);
    }

    public function creditLedgers(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function creditOrders(): HasMany
    {
        return $this->hasMany(CreditOrder::class);
    }

    public function creditMonthlyGrants(): HasMany
    {
        return $this->hasMany(CreditMonthlyGrant::class);
    }

    public function customerPricingRules(): HasMany
    {
        return $this->hasMany(CustomerPricingRule::class);
    }

    public function customerEntitlements(): HasMany
    {
        return $this->hasMany(CustomerEntitlement::class);
    }

    public function customerVoices(): HasMany
    {
        return $this->hasMany(CustomerVoice::class);
    }

    public function customerFiles(): HasMany
    {
        return $this->hasMany(CustomerFile::class);
    }

    public function mlJobs(): HasMany
    {
        return $this->hasMany(MlJob::class);
    }

    // =========================================================
    // Service subscription
    // =========================================================

    public function serviceSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerServiceSubscription::class);
    }

    public function activeServiceSubscription(): HasOne
    {
        return $this->hasOne(CustomerServiceSubscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function servicePlan(): HasOneThrough
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

    // =========================================================
    // Storage subscription
    // =========================================================

    public function storageSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerStorageSubscription::class);
    }

    public function activeStorageSubscription(): HasOne
    {
        return $this->hasOne(CustomerStorageSubscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function storagePlan(): HasOneThrough
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

    // =========================================================
    // Entitlement helper
    // =========================================================

    public function isAllowed(string $toolActionFullCode): bool
    {
        $action = ToolAction::where('full_code', $toolActionFullCode)->first();
        if (!$action) {
            return false;
        }

        $now = now();

        $override = CustomerEntitlement::query()
            ->where('customer_id', $this->id)
            ->where('tool_action_id', $action->id)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->first();

        if ($override && $override->allowed !== null) {
            return (bool) $override->allowed;
        }

        $plan = $this->servicePlan()->first();
        if (!$plan) {
            return false;
        }

        $ent = PlanEntitlement::query()
            ->where('service_plan_id', $plan->id)
            ->where('tool_action_id', $action->id)
            ->first();

        return $ent ? (bool) $ent->allowed : false;
    }

    // =========================================================
    // Pricing helper (new metered billing schema)
    // =========================================================

    public function priceCreditsFor(string $toolActionFullCode, array $context = []): int
    {
        $action = ToolAction::where('full_code', $toolActionFullCode)->first();
        if (!$action) {
            return 0;
        }

        $now = now();

        // 1) customer overrides first
        $customerRules = CustomerPricingRule::query()
            ->where('customer_id', $this->id)
            ->where('tool_action_id', $action->id)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('priority', 'asc')
            ->get();

        foreach ($customerRules as $rule) {
            if ($this->ruleMatches($rule->conditions, $context)) {
                return $this->calculateMeteredCredits(
                    metricCode: (string) $rule->metric_code,
                    unitSize: (float) $rule->unit_size,
                    creditsPerUnit: (float) $rule->credits_per_unit,
                    roundingMode: (string) ($rule->rounding_mode ?? 'ceil'),
                    roundingStep: (float) ($rule->rounding_step ?? 1),
                    minimumCredits: (int) ($rule->minimum_credits ?? 0),
                    context: $context
                );
            }
        }

        // 2) default pricing rules
        $rules = PricingRule::query()
            ->where('tool_action_id', $action->id)
            ->where('is_active', true)
            ->orderBy('priority', 'asc')
            ->get();

        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule->conditions, $context)) {
                return $this->calculateMeteredCredits(
                    metricCode: (string) $rule->metric_code,
                    unitSize: (float) $rule->unit_size,
                    creditsPerUnit: (float) $rule->credits_per_unit,
                    roundingMode: (string) ($rule->rounding_mode ?? 'ceil'),
                    roundingStep: (float) ($rule->rounding_step ?? 1),
                    minimumCredits: (int) ($rule->minimum_credits ?? 0),
                    context: $context
                );
            }
        }

        return 0;
    }

    protected function ruleMatches($conditions, array $context): bool
    {
        if (empty($conditions) || !is_array($conditions)) {
            return true;
        }

        foreach ($conditions as $key => $expected) {
            $actual = data_get($context, $key);

            if (is_array($expected)) {
                if (!in_array($actual, $expected, true)) {
                    return false;
                }
                continue;
            }

            if ($actual != $expected) {
                return false;
            }
        }

        return true;
    }

    protected function calculateMeteredCredits(
        string $metricCode,
        float $unitSize,
        float $creditsPerUnit,
        string $roundingMode,
        float $roundingStep,
        int $minimumCredits,
        array $context
    ): int {
        $quantity = $this->extractMetricQuantity($metricCode, $context);

        if ($quantity <= 0 || $unitSize <= 0 || $creditsPerUnit < 0) {
            return 0;
        }

        $rawUnits = $quantity / $unitSize;
        $rawCredits = $rawUnits * $creditsPerUnit;

        $rounded = match (strtolower($roundingMode)) {
            'floor' => floor($rawCredits),
            'round' => round($rawCredits),
            'none'  => $rawCredits,
            default => ceil($rawCredits),
        };

        $step = max(1, (float) $roundingStep);
        $stepped = ceil($rounded / $step) * $step;

        return (int) max($minimumCredits, (int) ceil($stepped));
    }

    protected function extractMetricQuantity(string $metricCode, array $context): float
    {
        return match ($metricCode) {
            'character', 'characters', 'char', 'chars'
                => (float) ($context['chars'] ?? $context['characters'] ?? 0),

            'minute', 'minutes'
                => (float) ($context['minutes'] ?? $context['duration_minutes'] ?? $context['minute'] ?? 0),

            'page', 'pages'
                => (float) ($context['pages'] ?? $context['page_count'] ?? 0),

            'stem_output', 'stem_outputs', 'output_stem'
                => (float) ($context['stem_outputs'] ?? $context['outputs'] ?? 0),

            default
                => (float) ($context['quantity'] ?? 0),
        };
    }
}
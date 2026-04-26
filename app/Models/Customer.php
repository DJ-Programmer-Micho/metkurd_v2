<?php

namespace App\Models;

use App\Services\Billing\CustomerBillingStateService;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;

    protected array $toolActionAllowanceCache = [];

    protected array $toolAccessCache = [];

    protected array $toolActionCodesByToolCache = [];

    protected array $toolActionCache = [];

    protected array $customerEntitlementCache = [];

    protected array $planEntitlementCache = [];

    protected array $customerPricingRulesCache = [];

    protected array $pricingRulesCache = [];

    protected bool $resolvedServicePlanLoaded = false;

    protected mixed $resolvedServicePlan = null;

    protected bool $resolvedStoragePlanLoaded = false;

    protected mixed $resolvedStoragePlan = null;

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
        'telegram_unverified_register_sent_at',
        'telegram_verified_register_sent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'telegram_unverified_register_sent_at' => 'datetime',
        'telegram_verified_register_sent_at' => 'datetime',
        'email_verify' => 'boolean',
        'phone_verify' => 'boolean',
        'password' => 'hashed',
    ];

    public function hasCompletedVerification(): bool
    {
        return (bool) $this->email_verify && (bool) $this->phone_verify;
    }

    public function nextVerificationRouteName(): ?string
    {
        if (! (bool) $this->email_verify) {
            return 'app.email.otp';
        }

        if (! (bool) $this->phone_verify) {
            return 'app.phone.otp';
        }

        return null;
    }

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

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'customer_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(CustomerPaymentMethod::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(\App\Domain\Payments\Models\Payment::class, 'customer_id');
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
            ->where('customer_service_subscriptions.status', 'active')
            ->where(function ($query) {
                $query
                    ->whereNull('customer_service_subscriptions.starts_at')
                    ->orWhere('customer_service_subscriptions.starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query
                    ->whereNull('customer_service_subscriptions.ends_at')
                    ->orWhere('customer_service_subscriptions.ends_at', '>=', now());
            })
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
        )
            ->where('customer_service_subscriptions.status', 'active')
            ->where(function ($query) {
                $query
                    ->whereNull('customer_service_subscriptions.starts_at')
                    ->orWhere('customer_service_subscriptions.starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query
                    ->whereNull('customer_service_subscriptions.ends_at')
                    ->orWhere('customer_service_subscriptions.ends_at', '>=', now());
            });
    }

    public function serviceCode(): string
    {
        return (string) ($this->currentServicePlan()?->code ?? 'free');
    }

    public function hasPaidServicePlan(): bool
    {
        $plan = $this->currentServicePlan();

        return $plan !== null && ! (bool) ($plan->is_free ?? false);
    }

    public function currentServicePlan(): ?ServicePlan
    {
        if ($this->resolvedServicePlanLoaded) {
            return $this->resolvedServicePlan;
        }

        $plan = null;

        if ($this->relationLoaded('servicePlan')) {
            $plan = $this->getRelation('servicePlan');
        }

        if (!$plan && $this->relationLoaded('activeServiceSubscription')) {
            $subscription = $this->getRelation('activeServiceSubscription');

            if ($subscription) {
                $subscription->loadMissing('servicePlan');
                $plan = $subscription->servicePlan;
            }
        }

        $planId = (int) ($this->getAttribute('service_plan_id') ?? 0);

        if (!$plan && $planId > 0) {
            $plan = ServicePlan::query()
                ->select(['id', 'code', 'name', 'monthly_credits', 'is_free'])
                ->find($planId);
        }

        if (!$plan) {
            $this->loadMissing(['activeServiceSubscription.servicePlan']);
            $plan = $this->activeServiceSubscription?->servicePlan;
        }

        if (! $plan) {
            $plan = app(CustomerBillingStateService::class)->defaultServicePlan();
        }

        if ($plan) {
            $this->setRelation('servicePlan', $plan);
        }

        $this->resolvedServicePlanLoaded = true;

        return $this->resolvedServicePlan = $plan ?: null;
    }

    public function currentServicePlanId(): ?int
    {
        $planId = (int) ($this->getAttribute('service_plan_id') ?? 0);

        if ($planId > 0) {
            return $planId;
        }

        return (int) ($this->currentServicePlan()?->id ?: 0) ?: null;
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
            ->where('customer_storage_subscriptions.status', 'active')
            ->where(function ($query) {
                $query
                    ->whereNull('customer_storage_subscriptions.starts_at')
                    ->orWhere('customer_storage_subscriptions.starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query
                    ->whereNull('customer_storage_subscriptions.ends_at')
                    ->orWhere('customer_storage_subscriptions.ends_at', '>=', now());
            })
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
        )
            ->where('customer_storage_subscriptions.status', 'active')
            ->where(function ($query) {
                $query
                    ->whereNull('customer_storage_subscriptions.starts_at')
                    ->orWhere('customer_storage_subscriptions.starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query
                    ->whereNull('customer_storage_subscriptions.ends_at')
                    ->orWhere('customer_storage_subscriptions.ends_at', '>=', now());
            });
    }

    public function storageQuotaMb(int $default = 512): int
    {
        return (int) ($this->currentStoragePlan()?->quota_mb ?? $default);
    }

    public function storageUsedBytes(): int
    {
        return (int) ($this->usage()->first()?->storage_used_bytes ?? 0);
    }

    public function currentStoragePlan(): ?StoragePlan
    {
        if ($this->resolvedStoragePlanLoaded) {
            return $this->resolvedStoragePlan;
        }

        $plan = null;

        if ($this->relationLoaded('storagePlan')) {
            $plan = $this->getRelation('storagePlan');
        }

        if (!$plan && $this->relationLoaded('activeStorageSubscription')) {
            $subscription = $this->getRelation('activeStorageSubscription');

            if ($subscription) {
                $subscription->loadMissing('storagePlan');
                $plan = $subscription->storagePlan;
            }
        }

        $planId = (int) ($this->getAttribute('storage_plan_id') ?? 0);

        if (!$plan && $planId > 0) {
            $plan = StoragePlan::query()
                ->select(['id', 'code', 'name', 'quota_mb'])
                ->find($planId);
        }

        if (!$plan) {
            $this->loadMissing(['activeStorageSubscription.storagePlan']);
            $plan = $this->activeStorageSubscription?->storagePlan;
        }

        if (! $plan) {
            $plan = app(CustomerBillingStateService::class)->defaultStoragePlan();
        }

        if ($plan) {
            $this->setRelation('storagePlan', $plan);
        }

        $this->resolvedStoragePlanLoaded = true;

        return $this->resolvedStoragePlan = $plan ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function servicePlanState(): array
    {
        return app(CustomerBillingStateService::class)->servicePlanState($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function storageQuotaState(): array
    {
        return app(CustomerBillingStateService::class)->storageQuotaState($this);
    }

    // =========================================================
    // Entitlement helper
    // =========================================================

    public function isAllowed(string $toolActionFullCode): bool
    {
        $toolActionFullCode = strtolower(trim($toolActionFullCode));

        if ($toolActionFullCode === '') {
            return false;
        }

        if (array_key_exists($toolActionFullCode, $this->toolActionAllowanceCache)) {
            return $this->toolActionAllowanceCache[$toolActionFullCode];
        }

        $action = $this->resolveToolAction($toolActionFullCode);

        if (! $action) {
            return $this->toolActionAllowanceCache[$toolActionFullCode] = false;
        }

        $override = $this->resolveCustomerEntitlement((int) $action->id);

        if ($override && $override->allowed !== null) {
            return $this->toolActionAllowanceCache[$toolActionFullCode] = (bool) $override->allowed;
        }

        $planId = $this->currentServicePlanId();

        if (! $planId) {
            return $this->toolActionAllowanceCache[$toolActionFullCode] = false;
        }

        $ent = $this->resolvePlanEntitlement($planId, (int) $action->id);

        return $this->toolActionAllowanceCache[$toolActionFullCode] = ($ent ? (bool) $ent->allowed : false);
    }

    public function canAccessTool(string $toolCode, array|string|null $toolActionFullCodes = null): bool
    {
        $toolCode = $this->normalizeToolCode($toolCode);

        if ($toolCode === '') {
            return false;
        }

        $requestedActionCodes = $this->normalizeCodeList($toolActionFullCodes);
        $cacheKey = $toolCode . '|' . implode(',', $requestedActionCodes);

        if (array_key_exists($cacheKey, $this->toolAccessCache)) {
            return $this->toolAccessCache[$cacheKey];
        }

        $actionCodes = ! empty($requestedActionCodes)
            ? array_values(array_filter($requestedActionCodes, fn (string $code) => str_starts_with($code, $toolCode . '.')))
            : $this->activeToolActionCodes($toolCode);

        if (empty($actionCodes)) {
            return $this->toolAccessCache[$cacheKey] = false;
        }

        foreach ($actionCodes as $actionCode) {
            if ($this->isAllowed($actionCode)) {
                return $this->toolAccessCache[$cacheKey] = true;
            }
        }

        return $this->toolAccessCache[$cacheKey] = false;
    }

    public function canAccessAnyTool(array|string|null $toolCodes): bool
    {
        foreach ($this->normalizeCodeList($toolCodes) as $toolCode) {
            if ($this->canAccessTool($toolCode)) {
                return true;
            }
        }

        return false;
    }

    public function canAccessMlJob(MlJob $job): bool
    {
        $job->loadMissing([
            'tool:id,code,is_active',
            'toolAction:id,tool_code,full_code,is_active',
        ]);

        $fullCode = strtolower(trim((string) ($job->toolAction?->full_code ?? '')));

        if ($fullCode !== '') {
            return $this->isAllowed($fullCode);
        }

        $toolCode = strtolower(trim((string) ($job->tool?->code ?? '')));

        if ($toolCode !== '') {
            return $this->canAccessTool($toolCode);
        }

        return match (strtolower(trim((string) $job->job_kind))) {
            'youtube_download' => $this->canAccessAnyTool(['youtube_audio', 'youtube_video']),
            default => $this->canAccessTool((string) $job->job_kind),
        };
    }

    // =========================================================
    // Pricing helper (new metered billing schema)
    // =========================================================

    public function priceCreditsFor(string $toolActionFullCode, array $context = []): int
    {
        $action = $this->resolveToolAction($toolActionFullCode);

        if (!$action) {
            return 0;
        }

        // 1) customer overrides first
        $customerRules = $this->resolveCustomerPricingRules((int) $action->id);

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
        $rules = $this->resolvePricingRules((int) $action->id);

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

    protected function activeToolActionCodes(string $toolCode): array
    {
        $toolCode = $this->normalizeToolCode($toolCode);

        if ($toolCode === '') {
            return [];
        }

        if (array_key_exists($toolCode, $this->toolActionCodesByToolCache)) {
            return $this->toolActionCodesByToolCache[$toolCode];
        }

        return $this->toolActionCodesByToolCache[$toolCode] = ToolAction::query()
            ->where('tool_code', $toolCode)
            ->where('is_active', true)
            ->whereHas('tool', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->pluck('full_code')
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->filter()
            ->values()
            ->all();
    }

    protected function resolveToolAction(string $toolActionFullCode): ?ToolAction
    {
        $toolActionFullCode = strtolower(trim($toolActionFullCode));

        if ($toolActionFullCode === '') {
            return null;
        }

        if (array_key_exists($toolActionFullCode, $this->toolActionCache)) {
            return $this->toolActionCache[$toolActionFullCode];
        }

        return $this->toolActionCache[$toolActionFullCode] = ToolAction::query()
            ->select(['id', 'tool_code', 'action_code', 'full_code', 'name', 'default_metric_code', 'is_active'])
            ->where('full_code', $toolActionFullCode)
            ->where('is_active', true)
            ->whereHas('tool', fn ($query) => $query->where('is_active', true))
            ->first();
    }

    protected function resolveCustomerEntitlement(int $toolActionId): ?CustomerEntitlement
    {
        if (array_key_exists($toolActionId, $this->customerEntitlementCache)) {
            return $this->customerEntitlementCache[$toolActionId];
        }

        $now = now();

        return $this->customerEntitlementCache[$toolActionId] = CustomerEntitlement::query()
            ->where('customer_id', $this->id)
            ->where('tool_action_id', $toolActionId)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->first();
    }

    protected function resolvePlanEntitlement(int $planId, int $toolActionId): ?PlanEntitlement
    {
        $cacheKey = $planId . ':' . $toolActionId;

        if (array_key_exists($cacheKey, $this->planEntitlementCache)) {
            return $this->planEntitlementCache[$cacheKey];
        }

        return $this->planEntitlementCache[$cacheKey] = PlanEntitlement::query()
            ->where('service_plan_id', $planId)
            ->where('tool_action_id', $toolActionId)
            ->first();
    }

    protected function resolveCustomerPricingRules(int $toolActionId)
    {
        if (array_key_exists($toolActionId, $this->customerPricingRulesCache)) {
            return $this->customerPricingRulesCache[$toolActionId];
        }

        $now = now();

        return $this->customerPricingRulesCache[$toolActionId] = CustomerPricingRule::query()
            ->where('customer_id', $this->id)
            ->where('tool_action_id', $toolActionId)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('priority', 'asc')
            ->get();
    }

    protected function resolvePricingRules(int $toolActionId)
    {
        if (array_key_exists($toolActionId, $this->pricingRulesCache)) {
            return $this->pricingRulesCache[$toolActionId];
        }

        return $this->pricingRulesCache[$toolActionId] = PricingRule::query()
            ->where('tool_action_id', $toolActionId)
            ->where('is_active', true)
            ->orderBy('priority', 'asc')
            ->get();
    }

    protected function normalizeCodeList(array|string|null $codes): array
    {
        return collect(is_array($codes) ? $codes : ($codes !== null ? [$codes] : []))
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeToolCode(string $toolCode): string
    {
        return match (strtolower(trim($toolCode))) {
            'wasr' => 'asr',
            default => strtolower(trim($toolCode)),
        };
    }
}

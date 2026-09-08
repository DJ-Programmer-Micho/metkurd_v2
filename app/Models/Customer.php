<?php

namespace App\Models;

use App\Services\Billing\CustomerBillingStateService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        return $this->hasOne(CreditWallet::class)
            ->where('wallet_type', CreditWallet::TYPE_APP);
    }

    public function appWallet(): HasOne
    {
        return $this->wallet();
    }

    public function apiWallet(): HasOne
    {
        return $this->hasOne(CreditWallet::class)
            ->where('wallet_type', CreditWallet::TYPE_API);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(CreditWallet::class);
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

    public function apiKeys(): HasMany
    {
        return $this->hasMany(CustomerApiKey::class);
    }

    public function apiJobs(): HasMany
    {
        return $this->hasMany(ApiJob::class);
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

        if (! $plan && $this->relationLoaded('activeServiceSubscription')) {
            $subscription = $this->getRelation('activeServiceSubscription');

            if ($subscription) {
                $subscription->loadMissing('servicePlan');
                $plan = $subscription->servicePlan;
            }
        }

        $planId = (int) ($this->getAttribute('service_plan_id') ?? 0);

        if (! $plan && $planId > 0) {
            $plan = ServicePlan::query()
                ->select([
                    'id',
                    'code',
                    'name',
                    'monthly_credits',
                    'app_monthly_credits',
                    'api_monthly_credits',
                    'concurrent_jobs_limit',
                    'api_enabled',
                    'api_requests_per_minute',
                    'api_concurrent_jobs',
                    'api_allowed_tools',
                    'is_free',
                ])
                ->find($planId);
        }

        if (! $plan) {
            $subscription = $this->activeServiceSubscription()
                ->with('servicePlan')
                ->first();

            if ($subscription) {
                $this->setRelation('activeServiceSubscription', $subscription);
                $plan = $subscription->servicePlan;
            }
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

        if (! $plan && $this->relationLoaded('activeStorageSubscription')) {
            $subscription = $this->getRelation('activeStorageSubscription');

            if ($subscription) {
                $subscription->loadMissing('storagePlan');
                $plan = $subscription->storagePlan;
            }
        }

        $planId = (int) ($this->getAttribute('storage_plan_id') ?? 0);

        if (! $plan && $planId > 0) {
            $plan = StoragePlan::query()
                ->select(['id', 'code', 'name', 'quota_mb'])
                ->find($planId);
        }

        if (! $plan) {
            $subscription = $this->activeStorageSubscription()
                ->with('storagePlan')
                ->first();

            if ($subscription) {
                $this->setRelation('activeStorageSubscription', $subscription);
                $plan = $subscription->storagePlan;
            }
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

    public function syncResolvedServicePlan(?CustomerServiceSubscription $subscription = null): static
    {
        $this->resolvedServicePlanLoaded = false;
        $this->resolvedServicePlan = null;
        $this->toolActionAllowanceCache = [];
        $this->toolAccessCache = [];
        $this->customerEntitlementCache = [];
        $this->planEntitlementCache = [];
        $this->customerPricingRulesCache = [];
        $this->pricingRulesCache = [];
        $this->unsetRelation('servicePlan');
        $this->unsetRelation('activeServiceSubscription');

        if ($subscription) {
            $subscription->loadMissing('servicePlan');
            $this->setRelation('activeServiceSubscription', $subscription);

            if ($subscription->servicePlan) {
                $this->setRelation('servicePlan', $subscription->servicePlan);
            }
        }

        return $this;
    }

    public function syncResolvedStoragePlan(?CustomerStorageSubscription $subscription = null): static
    {
        $this->resolvedStoragePlanLoaded = false;
        $this->resolvedStoragePlan = null;
        $this->unsetRelation('storagePlan');
        $this->unsetRelation('activeStorageSubscription');

        if ($subscription) {
            $subscription->loadMissing('storagePlan');
            $this->setRelation('activeStorageSubscription', $subscription);

            if ($subscription->storagePlan) {
                $this->setRelation('storagePlan', $subscription->storagePlan);
            }
        }

        return $this;
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

    public function isAllowed(string $toolActionFullCode, string $channel = PlanEntitlement::CHANNEL_APP): bool
    {
        $toolActionFullCode = strtolower(trim($toolActionFullCode));
        $channel = PlanEntitlement::normalizeChannel($channel, PlanEntitlement::CHANNEL_APP, true);

        if ($toolActionFullCode === '') {
            return false;
        }

        $cacheKey = $toolActionFullCode.'|'.$channel;

        if (array_key_exists($cacheKey, $this->toolActionAllowanceCache)) {
            return $this->toolActionAllowanceCache[$cacheKey];
        }

        $action = $this->resolveToolAction($toolActionFullCode);

        if (! $action) {
            return $this->toolActionAllowanceCache[$cacheKey] = false;
        }

        $override = $this->resolveCustomerEntitlement((int) $action->id, $channel);

        if ($override && $override->allowed !== null) {
            return $this->toolActionAllowanceCache[$cacheKey] = (bool) $override->allowed;
        }

        $planId = $this->currentServicePlanId();

        if (! $planId) {
            return $this->toolActionAllowanceCache[$cacheKey] = false;
        }

        $ent = $this->resolvePlanEntitlement($planId, (int) $action->id, $channel);

        return $this->toolActionAllowanceCache[$cacheKey] = ($ent ? (bool) $ent->allowed : false);
    }

    public function canAccessTool(
        string $toolCode,
        array|string|null $toolActionFullCodes = null,
        string $channel = PlanEntitlement::CHANNEL_APP
    ): bool {
        $toolCode = $this->normalizeToolCode($toolCode);
        $channel = PlanEntitlement::normalizeChannel($channel, PlanEntitlement::CHANNEL_APP, true);

        if ($toolCode === '') {
            return false;
        }

        $requestedActionCodes = $this->normalizeCodeList($toolActionFullCodes);
        $cacheKey = $toolCode.'|'.$channel.'|'.implode(',', $requestedActionCodes);

        if (array_key_exists($cacheKey, $this->toolAccessCache)) {
            return $this->toolAccessCache[$cacheKey];
        }

        $actionCodes = ! empty($requestedActionCodes)
            ? array_values(array_filter($requestedActionCodes, fn (string $code) => str_starts_with($code, $toolCode.'.')))
            : $this->activeToolActionCodes($toolCode);

        if (empty($actionCodes)) {
            return $this->toolAccessCache[$cacheKey] = false;
        }

        foreach ($actionCodes as $actionCode) {
            if ($this->isAllowed($actionCode, $channel)) {
                return $this->toolAccessCache[$cacheKey] = true;
            }
        }

        return $this->toolAccessCache[$cacheKey] = false;
    }

    public function canAccessAnyTool(array|string|null $toolCodes, string $channel = PlanEntitlement::CHANNEL_APP): bool
    {
        foreach ($this->normalizeCodeList($toolCodes) as $toolCode) {
            if ($this->canAccessTool($toolCode, channel: $channel)) {
                return true;
            }
        }

        return false;
    }

    public function canAccessMlJob(MlJob $job, string $channel = PlanEntitlement::CHANNEL_APP): bool
    {
        $job->loadMissing([
            'tool:id,code,is_active',
            'toolAction:id,tool_code,full_code,is_active',
        ]);

        $fullCode = strtolower(trim((string) ($job->toolAction?->full_code ?? '')));

        if ($fullCode !== '') {
            return $this->isAllowed($fullCode, $channel);
        }

        $toolCode = strtolower(trim((string) ($job->tool?->code ?? '')));

        if ($toolCode !== '') {
            return $this->canAccessTool($toolCode, channel: $channel);
        }

        return match (strtolower(trim((string) $job->job_kind))) {
            'youtube_download' => $this->canAccessAnyTool(['youtube_audio', 'youtube_video'], $channel),
            default => $this->canAccessTool((string) $job->job_kind, channel: $channel),
        };
    }

    // =========================================================
    // Pricing helper (new metered billing schema)
    // =========================================================

    public function priceCreditsFor(
        string $toolActionFullCode,
        array $context = [],
        string $channel = PricingRule::CHANNEL_APP
    ): int {
        $channel = PricingRule::normalizeChannel(data_get($context, 'channel', $channel), PricingRule::CHANNEL_APP);
        $context['channel'] = $channel;
        $rule = $this->resolvedPricingRuleFor($toolActionFullCode, $context, $channel);
        if (! $rule) {
            return 0;
        }

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

    /** The runtime-selected rule, exposed for read-only Admin quote explanations. */
    public function resolvedPricingRuleFor(string $actionCode, array $context = [], string $channel = PricingRule::CHANNEL_APP): PricingRule|CustomerPricingRule|null
    {
        $channel = PricingRule::normalizeChannel(data_get($context, 'channel', $channel), PricingRule::CHANNEL_APP);
        $context['channel'] = $channel;
        $action = $this->resolveToolAction($actionCode);
        if (! $action) {
            return null;
        }
        foreach ([$this->resolveCustomerPricingRules((int) $action->id, $channel), $this->resolvePricingRules((int) $action->id, $channel)] as $rules) {
            foreach ($rules as $rule) {
                if ($this->ruleMatches($rule->conditions, $context)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    protected function ruleMatches($conditions, array $context): bool
    {
        if (empty($conditions) || ! is_array($conditions)) {
            return true;
        }

        foreach ($conditions as $key => $expected) {
            $actual = data_get($context, $key);

            if (is_array($expected)) {
                if (! in_array($actual, $expected, true)) {
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
            'round', 'nearest' => round($rawCredits),
            'none' => $rawCredits,
            default => ceil($rawCredits),
        };

        $step = max(1, (float) $roundingStep);
        $stepped = ceil($rounded / $step) * $step;

        return (int) max($minimumCredits, (int) ceil($stepped));
    }

    protected function extractMetricQuantity(string $metricCode, array $context): float
    {
        $metricCode = strtolower(trim($metricCode));

        return match ($metricCode) {
            'character', 'characters', 'char', 'chars' => (float) ($context['chars'] ?? $context['characters'] ?? 0),

            'minute', 'minutes' => (float) ($context['minutes'] ?? $context['duration_minutes'] ?? $context['minute'] ?? 0),

            'second', 'seconds' => (float) ($context['seconds'] ?? $context['duration_seconds'] ?? $context['second'] ?? 0),

            'page', 'pages' => (float) ($context['pages'] ?? $context['page_count'] ?? 0),

            'file', 'files' => (float) ($context['files'] ?? $context['file_count'] ?? $context['file'] ?? 0),

            'request', 'requests' => (float) ($context['requests'] ?? $context['request_count'] ?? $context['request'] ?? 0),

            'token', 'tokens' => (float) ($context['tokens'] ?? $context['token_count'] ?? $context['token'] ?? 0),

            'storage', 'storage_gb', 'gb', 'gigabyte', 'gigabytes' => (float) (
                $context['storage_gb']
                ?? $context['gigabytes']
                ?? $context['gb']
                ?? $context['storage']
                ?? (
                    array_key_exists('storage_mb', $context)
                        ? ((float) $context['storage_mb'] / 1024)
                        : (
                            (array_key_exists('storage_bytes', $context) || array_key_exists('bytes', $context))
                                ? ((float) ($context['storage_bytes'] ?? $context['bytes'] ?? 0) / 1073741824)
                                : 0
                        )
                )
            ),

            'stem_output', 'stem_outputs', 'output_stem' => (float) ($context['stem_outputs'] ?? $context['outputs'] ?? 0),

            default => (float) ($context['quantity'] ?? 0),
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

    protected function resolveCustomerEntitlement(int $toolActionId, string $channel = PlanEntitlement::CHANNEL_APP): ?CustomerEntitlement
    {
        $channel = PlanEntitlement::normalizeChannel($channel, PlanEntitlement::CHANNEL_APP, true);
        $cacheKey = $toolActionId.'|'.$channel;

        if (array_key_exists($cacheKey, $this->customerEntitlementCache)) {
            return $this->customerEntitlementCache[$cacheKey];
        }

        $now = now();
        $fallbackChannels = PlanEntitlement::fallbackChannels($channel, true);

        return $this->customerEntitlementCache[$cacheKey] = CustomerEntitlement::query()
            ->where('customer_id', $this->id)
            ->where('tool_action_id', $toolActionId)
            ->whereIn('entitlement_channel', $fallbackChannels)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByRaw($this->channelPriorityCase('entitlement_channel', $fallbackChannels), $fallbackChannels)
            ->first();
    }

    protected function resolvePlanEntitlement(
        int $planId,
        int $toolActionId,
        string $channel = PlanEntitlement::CHANNEL_APP
    ): ?PlanEntitlement {
        $channel = PlanEntitlement::normalizeChannel($channel, PlanEntitlement::CHANNEL_APP, true);
        $cacheKey = $planId.':'.$toolActionId.':'.$channel;

        if (array_key_exists($cacheKey, $this->planEntitlementCache)) {
            return $this->planEntitlementCache[$cacheKey];
        }

        $fallbackChannels = PlanEntitlement::fallbackChannels($channel, true);

        return $this->planEntitlementCache[$cacheKey] = PlanEntitlement::query()
            ->where('service_plan_id', $planId)
            ->where('tool_action_id', $toolActionId)
            ->whereIn('entitlement_channel', $fallbackChannels)
            ->orderByRaw($this->channelPriorityCase('entitlement_channel', $fallbackChannels), $fallbackChannels)
            ->first();
    }

    protected function resolveCustomerPricingRules(int $toolActionId, string $channel = PricingRule::CHANNEL_APP)
    {
        $channel = PricingRule::normalizeChannel($channel, PricingRule::CHANNEL_APP);
        $cacheKey = $toolActionId.'|'.$channel;

        if (array_key_exists($cacheKey, $this->customerPricingRulesCache)) {
            return $this->customerPricingRulesCache[$cacheKey];
        }

        $now = now();
        $fallbackChannels = PricingRule::fallbackChannels($channel);

        return $this->customerPricingRulesCache[$cacheKey] = CustomerPricingRule::query()
            ->where('customer_id', $this->id)
            ->where('tool_action_id', $toolActionId)
            ->whereIn('pricing_channel', $fallbackChannels)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByRaw($this->channelPriorityCase('pricing_channel', $fallbackChannels), $fallbackChannels)
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }

    protected function resolvePricingRules(int $toolActionId, string $channel = PricingRule::CHANNEL_APP)
    {
        $channel = PricingRule::normalizeChannel($channel, PricingRule::CHANNEL_APP);
        $planId = (int) ($this->currentServicePlanId() ?? 0);
        $cacheKey = $toolActionId.'|'.$planId.'|'.$channel;

        if (array_key_exists($cacheKey, $this->pricingRulesCache)) {
            return $this->pricingRulesCache[$cacheKey];
        }

        $now = now();
        $fallbackChannels = PricingRule::fallbackChannels($channel);
        $query = PricingRule::query()
            ->where('tool_action_id', $toolActionId)
            ->whereIn('pricing_channel', $fallbackChannels)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($q) use ($planId) {
                if ($planId > 0) {
                    $q->whereNull('service_plan_id')
                        ->orWhere('service_plan_id', $planId);

                    return;
                }

                $q->whereNull('service_plan_id');
            });

        if ($planId > 0) {
            $query->orderByRaw('CASE WHEN service_plan_id = ? THEN 0 ELSE 1 END', [$planId]);
        }

        return $this->pricingRulesCache[$cacheKey] = $query
            ->orderByRaw($this->channelPriorityCase('pricing_channel', $fallbackChannels), $fallbackChannels)
            ->orderByDesc('priority')
            ->orderByDesc('id')
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

    protected function channelPriorityCase(string $column, array $channels): string
    {
        $clauses = [];

        foreach (array_values($channels) as $index => $value) {
            $clauses[] = "WHEN {$column} = ? THEN {$index}";
        }

        return 'CASE '.implode(' ', $clauses).' ELSE '.count($channels).' END';
    }
}

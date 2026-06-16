<?php

namespace App\Models;

use App\Domain\Payments\Enums\PaymentMode;
use App\Services\Plans\PlanConcurrencyService;
use App\Support\LandingPricingCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ServicePlan extends Model
{
    use HasFactory;

    protected $table = 'service_plans';

    protected $fillable = [
        'code',
        'name',
        'billing_interval',
        'billing_intervals',
        'payment_mode',
        'monthly_credits',
        'app_monthly_credits',
        'api_monthly_credits',
        'concurrent_jobs_limit',
        'api_enabled',
        'api_requests_per_minute',
        'api_concurrent_jobs',
        'api_allowed_tools',
        'is_free',
        'is_active',
        'sort_order',
        'price_usd_monthly',
        'price_usd_yearly',
        'price_iqd_monthly',
        'price_iqd_yearly',
        'ui_features',
        'meta',
    ];

    protected $casts = [
        'monthly_credits' => 'integer',
        'app_monthly_credits' => 'integer',
        'api_monthly_credits' => 'integer',
        'concurrent_jobs_limit' => 'integer',
        'api_enabled' => 'boolean',
        'api_requests_per_minute' => 'integer',
        'api_concurrent_jobs' => 'integer',
        'api_allowed_tools' => 'array',
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'price_usd_monthly' => 'decimal:2',
        'price_usd_yearly' => 'decimal:2',
        'price_iqd_monthly' => 'decimal:0',
        'price_iqd_yearly' => 'decimal:0',
        'billing_intervals' => 'array',
        'ui_features' => 'array',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ServicePlan $plan): void {
            $appMonthlyCredits = $plan->getAttribute('app_monthly_credits');
            $legacyMonthlyCredits = $plan->getAttribute('monthly_credits');

            if ($plan->isDirty('monthly_credits') && ! $plan->isDirty('app_monthly_credits')) {
                $resolvedAppMonthlyCredits = $legacyMonthlyCredits ?? 0;
            } elseif ($plan->isDirty('app_monthly_credits')) {
                $resolvedAppMonthlyCredits = $appMonthlyCredits ?? 0;
            } else {
                $resolvedAppMonthlyCredits = $appMonthlyCredits ?? $legacyMonthlyCredits ?? 0;
            }

            $plan->setAttribute('app_monthly_credits', max(0, (int) $resolvedAppMonthlyCredits));
            $plan->setAttribute('monthly_credits', max(0, (int) $resolvedAppMonthlyCredits));
            $plan->setAttribute('api_monthly_credits', max(0, (int) ($plan->getAttribute('api_monthly_credits') ?? 0)));
        });

        $refreshPlanCaches = static function (): void {
            app(PlanConcurrencyService::class)->flushCache();
            app(LandingPricingCatalog::class)->flushServicePlanCache();
            static::bumpCacheVersion();
        };

        static::saved($refreshPlanCaches);
        static::deleted($refreshPlanCaches);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerServiceSubscription::class, 'service_plan_id');
    }

    public function previousSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerServiceSubscription::class, 'previous_service_plan_id');
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class, 'service_plan_id');
    }

    public function monthlyGrants(): HasMany
    {
        return $this->hasMany(CreditMonthlyGrant::class, 'service_plan_id');
    }

    public function planEntitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class, 'service_plan_id');
    }

    public function voiceAccesses(): HasMany
    {
        return $this->hasMany(PlanVoiceAccess::class, 'service_plan_id');
    }

    public function activeVoiceAccesses(): HasMany
    {
        return $this->voiceAccesses()->where('is_active', true);
    }

    public function priceUsdForCycle(string $billingCycle = 'monthly'): float
    {
        $billingCycle = strtolower(trim($billingCycle));

        return $billingCycle === 'yearly'
            ? (float) ($this->price_usd_yearly ?? 0)
            : (float) ($this->price_usd_monthly ?? 0);
    }

    public function priceIqdForCycle(string $billingCycle = 'monthly'): int
    {
        $billingCycle = strtolower(trim($billingCycle));
        $stored = $billingCycle === 'yearly'
            ? $this->price_iqd_yearly
            : $this->price_iqd_monthly;

        if ($stored !== null) {
            return (int) round((float) $stored);
        }

        return app(\App\Services\Billing\BillingCurrencyService::class)
            ->legacyUsdAmountToIqd($this->priceUsdForCycle($billingCycle));
    }

    public function checkoutPaymentMode(): PaymentMode
    {
        return PaymentMode::fromValue($this->payment_mode, PaymentMode::RECURRING);
    }

    public function appMonthlyCredits(): int
    {
        return max(0, (int) ($this->app_monthly_credits ?? $this->monthly_credits ?? 0));
    }

    public function apiMonthlyCredits(): int
    {
        return max(0, (int) ($this->api_monthly_credits ?? 0));
    }

    public function checkoutPaymentModeValue(): string
    {
        return $this->checkoutPaymentMode()->value;
    }

    public function billingIntervals(): array
    {
        $allowed = ['monthly', 'yearly', 'lifetime'];
        $configured = collect(is_array($this->billing_intervals) ? $this->billing_intervals : [])
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        $legacy = strtolower(trim((string) ($this->billing_interval ?? 'monthly')));

        if ($legacy === 'lifetime') {
            return ['lifetime'];
        }

        if (in_array($legacy, ['monthly', 'yearly'], true)) {
            return ['monthly', 'yearly'];
        }

        return ['monthly'];
    }

    public function supportsBillingInterval(string $cycle): bool
    {
        $cycle = strtolower(trim($cycle));
        $normalized = $cycle === 'hourly' ? 'monthly' : $cycle;

        return in_array($normalized, $this->billingIntervals(), true);
    }

    public function localizedUiFeatures(?string $locale = null): array
    {
        $locale = $this->normalizeUiLocale($locale ?? app()->getLocale());
        $payload = is_array($this->ui_features) ? $this->ui_features : [];

        if ($payload === []) {
            return $this->normalizeUiFeatureBlock([]);
        }

        if (! $this->hasLocalizedUiFeatureShape($payload)) {
            return $this->normalizeUiFeatureBlock($payload);
        }

        $localized = $payload[$locale] ?? null;

        if (! is_array($localized) || $localized === []) {
            $localized = $payload['en'] ?? null;
        }

        if (! is_array($localized) || $localized === []) {
            $localized = collect($payload)
                ->first(fn ($value) => is_array($value) && $this->looksLikeUiFeatureBlock($value), []);
        }

        return $this->normalizeUiFeatureBlock(is_array($localized) ? $localized : []);
    }

    protected function hasLocalizedUiFeatureShape(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($this->normalizeUiLocale((string) $key) === null) {
                continue;
            }

            if ($this->looksLikeUiFeatureBlock($value)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeUiFeatureBlock(array $block): bool
    {
        return Arr::hasAny($block, ['badge', 'title', 'summary', 'cta', 'recommended', 'featured', 'features']);
    }

    protected function normalizeUiFeatureBlock(array $block): array
    {
        $features = collect(Arr::wrap($block['features'] ?? []))
            ->map(function ($feature) {
                if (is_string($feature)) {
                    return trim($feature);
                }

                if (is_array($feature)) {
                    return trim((string) (data_get($feature, 'label', data_get($feature, 'title', ''))));
                }

                return '';
            })
            ->filter(fn (string $feature) => $feature !== '')
            ->values()
            ->all();

        return [
            'badge' => filled($block['badge'] ?? null) ? (string) $block['badge'] : null,
            'title' => filled($block['title'] ?? null) ? (string) $block['title'] : null,
            'summary' => filled($block['summary'] ?? null) ? (string) $block['summary'] : null,
            'recommended' => (bool) ($block['recommended'] ?? $block['featured'] ?? false),
            'cta' => filled($block['cta'] ?? null) ? (string) $block['cta'] : null,
            'features' => $features,
        ];
    }

    protected function normalizeUiLocale(?string $locale): ?string
    {
        $locale = str_replace('_', '-', strtolower(trim((string) $locale)));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) === 1 ? substr($locale, 0, 2) : null;
    }

    protected static function bumpCacheVersion(): void
    {
        $currentVersion = max(1, (int) Cache::get('service-plans:cache-version', 1));

        Cache::forever('service-plans:cache-version', $currentVersion + 1);
    }
}

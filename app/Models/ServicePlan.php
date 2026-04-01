<?php

namespace App\Models;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePlan extends Model
{
    use HasFactory;

    protected $table = 'service_plans';

    protected $fillable = [
        'code',
        'name',
        'billing_interval',
        'monthly_credits',
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
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'price_usd_monthly' => 'decimal:2',
        'price_usd_yearly' => 'decimal:2',
        'price_iqd_monthly' => 'decimal:0',
        'price_iqd_yearly' => 'decimal:0',
        'ui_features' => 'array',
        'meta' => 'array',
    ];

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
}

<?php

namespace App\Support;

use App\Models\CreditProduct;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class LandingPricingCatalog
{
    public function flushServicePlanCache(): void
    {
        foreach ((array) config('app.locales', ['en']) as $locale) {
            Cache::forget("landing.service-plans.catalog.v5.{$locale}");
        }
    }

    public function servicePlans(): array
    {
        $locale = app()->getLocale();

        return Cache::remember("landing.service-plans.catalog.v5.{$locale}", now()->addMinutes(15), function () {
            return ServicePlan::query()
                ->where('is_active', true)
                ->withCount([
                    'planEntitlements as allowed_entitlements_count' => fn ($query) => $query->where('allowed', true),
                ])
                ->orderBy('sort_order')
                ->get([
                    'code',
                    'name',
                    'monthly_credits',
                    'app_monthly_credits',
                    'is_free',
                    'price_usd_monthly',
                    'price_usd_yearly',
                    'price_iqd_monthly',
                    'price_iqd_yearly',
                    'ui_features',
                    'meta',
                    'sort_order',
                ])
                ->map(fn (ServicePlan $plan) => $this->formatServicePlan($plan))
                ->all();
        });
    }

    public function storagePlans(): array
    {
        $locale = app()->getLocale();

        return Cache::remember("landing.storage-plans.catalog.v1.{$locale}", now()->addMinutes(15), function () {
            return StoragePlan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get([
                    'code',
                    'name',
                    'quota_mb',
                    'price_usd',
                    'price_iqd',
                    'sort_order',
                ])
                ->map(fn (StoragePlan $plan) => $this->formatStoragePlan($plan))
                ->filter(fn (array $plan) => (int) ($plan['price_iqd'] ?? 0) > 0)
                ->values()
                ->all();
        });
    }

    public function creditProducts(): array
    {
        $locale = app()->getLocale();

        return Cache::remember("landing.credit-products.catalog.v1.{$locale}", now()->addMinutes(15), function () {
            return CreditProduct::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get([
                    'code',
                    'name',
                    'credits_amount',
                    'price_usd',
                    'price_iqd',
                    'meta',
                    'sort_order',
                ])
                ->map(fn (CreditProduct $product) => $this->formatCreditProduct($product))
                ->filter(fn (array $product) => (int) ($product['price_iqd'] ?? 0) > 0)
                ->values()
                ->all();
        });
    }

    protected function formatServicePlan(ServicePlan $plan): array
    {
        $ui = $plan->localizedUiFeatures(app()->getLocale());
        $meta = is_array($plan->meta) ? $plan->meta : [];

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'title' => $ui['title'] ?? data_get($meta, 'title', $plan->name),
            'summary' => $ui['summary'] ?? data_get($meta, 'summary'),
            'monthly_credits' => (int) $plan->appMonthlyCredits(),
            'is_free' => (bool) $plan->is_free,
            'price_iqd_monthly' => $plan->priceIqdForCycle('monthly'),
            'price_iqd_yearly' => $plan->priceIqdForCycle('yearly'),
            'allowed_entitlements_count' => (int) $plan->allowed_entitlements_count,
            'features' => $ui['features'] !== [] ? $ui['features'] : $this->featureList([], $meta),
            'badge' => $ui['badge'] ?? data_get($meta, 'badge'),
            'featured' => (bool) ($ui['recommended'] ?? data_get($meta, 'recommended', data_get($meta, 'featured', false))),
            'cta' => $ui['cta'] ?? data_get($meta, 'cta', $plan->is_free ? 'Try it free' : 'Get Started'),
        ];
    }

    protected function formatStoragePlan(StoragePlan $plan): array
    {
        $quotaLabel = $this->storageQuotaLabel((int) $plan->quota_mb);

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'title' => $plan->name,
            'summary' => __('Secure storage for up to :quota.', ['quota' => $quotaLabel]),
            'quota_mb' => (int) $plan->quota_mb,
            'quota_label' => $quotaLabel,
            'price_iqd' => $plan->priceIqdAmount(),
            'features' => [
                __('Storage quota: :quota', ['quota' => $quotaLabel]),
                __('One-time storage upgrade pricing'),
            ],
        ];
    }

    protected function formatCreditProduct(CreditProduct $product): array
    {
        $meta = is_array($product->meta) ? $product->meta : [];

        return [
            'code' => $product->code,
            'name' => $product->name,
            'title' => data_get($meta, 'title', $product->name),
            'summary' => data_get($meta, 'summary', __('One-time credit top-up for heavier workloads.')),
            'credits_amount' => (int) $product->credits_amount,
            'price_iqd' => $product->priceIqdAmount(),
            'badge' => data_get($meta, 'badge'),
            'features' => $this->featureList([], $meta, [
                __('Credits included: :value', ['value' => number_format((int) $product->credits_amount)]),
                __('One-time purchase'),
            ]),
        ];
    }

    protected function featureList(array $ui = [], array $meta = [], array $fallback = []): array
    {
        $rawFeatures = data_get($ui, 'features', data_get($meta, 'features', data_get($meta, 'ui_features.features', $fallback)));

        return collect(Arr::wrap($rawFeatures))
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
            ->unique()
            ->values()
            ->all();
    }

    protected function storageQuotaLabel(int $quotaMb): string
    {
        if ($quotaMb >= 1024) {
            $gb = $quotaMb / 1024;

            return fmod($gb, 1.0) === 0.0
                ? number_format($gb, 0).' GB'
                : number_format($gb, 1).' GB';
        }

        return number_format($quotaMb).' MB';
    }
}

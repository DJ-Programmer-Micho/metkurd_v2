<?php

use App\Models\ServicePlan;
use App\Support\LandingContent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $limit = 0;
    public bool $showToggle = true;
    public string $switchStyle = 'toggle';
    public array $codes = [];

    #[Computed]
    public function plans(): array
    {
        $plans = Cache::remember('landing.active-service-plans.v2', now()->addMinutes(15), function () {
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
                    'is_free',
                    'price_usd_monthly',
                    'price_usd_yearly',
                    'ui_features',
                    'meta',
                    'sort_order',
                ])
                ->map(fn (ServicePlan $plan) => $this->formatPlan($plan))
                ->all();
        });

        $merged = collect($plans)
            ->map(fn ($plan) => $this->normalizePlan($plan))
            ->values();

        if (! empty($this->codes)) {
            $codeOrder = array_values(array_filter(array_map('strtolower', $this->codes)));

            $merged = collect($codeOrder)
                ->map(function (string $code) use ($merged) {
                    return $merged->firstWhere('code', $code);
                })
                ->filter()
                ->values();
        }

        if ($this->limit > 0) {
            $merged = $merged->take($this->limit)->values();
        }

        return $merged->all();
    }

    protected function formatPlan(ServicePlan $plan): array
    {
        $ui = is_array($plan->ui_features) ? $plan->ui_features : [];
        $meta = is_array($plan->meta) ? $plan->meta : [];

        $features = Arr::wrap(data_get($ui, 'features', data_get($meta, 'features', [])));
        $features = array_values(array_filter($features, fn ($feature) => is_string($feature) && trim($feature) !== ''));

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'title' => data_get($ui, 'title', data_get($meta, 'title', $plan->name)),
            'summary' => data_get($ui, 'summary', data_get($meta, 'summary')),
            'monthly_credits' => (int) $plan->monthly_credits,
            'is_free' => (bool) $plan->is_free,
            'price_usd_monthly' => (float) $plan->price_usd_monthly,
            'price_usd_yearly' => (float) $plan->price_usd_yearly,
            'allowed_entitlements_count' => (int) $plan->allowed_entitlements_count,
            'features' => $features,
            'badge' => data_get($ui, 'badge', data_get($meta, 'badge')),
            'featured' => (bool) data_get($ui, 'recommended', data_get($ui, 'featured', data_get($meta, 'recommended', data_get($meta, 'featured', false)))),
            'cta' => data_get($ui, 'cta', data_get($meta, 'cta', $plan->is_free ? 'Try it free' : 'Get Started')),
        ];
    }

    protected function normalizePlan(mixed $plan): array
    {
        if ($plan instanceof ServicePlan) {
            return $this->formatPlan($plan);
        }

        $plan = is_array($plan) ? $plan : [];
        $isFree = (bool) ($plan['is_free'] ?? false);

        return [
            'code' => (string) ($plan['code'] ?? ''),
            'name' => (string) ($plan['name'] ?? ''),
            'title' => (string) ($plan['title'] ?? ($plan['name'] ?? '')),
            'summary' => filled($plan['summary'] ?? null) ? (string) $plan['summary'] : null,
            'monthly_credits' => (int) ($plan['monthly_credits'] ?? 0),
            'is_free' => $isFree,
            'price_usd_monthly' => (float) ($plan['price_usd_monthly'] ?? 0),
            'price_usd_yearly' => (float) ($plan['price_usd_yearly'] ?? 0),
            'allowed_entitlements_count' => (int) ($plan['allowed_entitlements_count'] ?? 0),
            'features' => array_values(array_filter(Arr::wrap($plan['features'] ?? []), fn ($feature) => is_string($feature) && trim($feature) !== '')),
            'badge' => filled($plan['badge'] ?? null) ? (string) $plan['badge'] : null,
            'featured' => (bool) ($plan['featured'] ?? false),
            'cta' => (string) ($plan['cta'] ?? ($isFree ? 'Try it free' : 'Get Started')),
        ];
    }

    public function ctaHref(): string
    {
        if (auth('admin')->check()) {
            return route('admin.home', ['locale' => app()->getLocale()]);
        }

        if (auth('app')->check()) {
            return route('app.home', ['locale' => app()->getLocale()]);
        }

        return route('app.signup');
    }
};
?>

@php
    $ctaHref = $this->ctaHref();
@endphp

<div data-pricing-root>
    @if($showToggle)
        <div class="d-flex justify-content-center mb-5">
            <div class="d-flex justify-content-center align-items-center gap-3 pricing-switch reveal">
                <span>{{ LandingContent::text('common.monthly') }}</span>
                <div class="form-check form-switch m-0">
                    <input
                        class="form-check-input"
                        id="billingToggle"
                        type="checkbox"
                        aria-label="{{ LandingContent::text('common.toggle_yearly') }}"
                        data-billing-checkbox
                    >
                </div>
                <span>{{ LandingContent::text('common.yearly') }}</span>
            </div>
        </div>
    @endif

    <div class="row g-4 justify-content-center">
        @foreach($this->plans as $plan)
            @php
                $isFeatured = (bool) ($plan['featured'] ?? false);
                $badge = $plan['badge'] ?? null;
                $title = $plan['title'] ?? $plan['name'] ?? '';
                $summary = $plan['summary'] ?? null;
                $monthlyCredits = (int) ($plan['monthly_credits'] ?? 0);
                $allowedActions = (int) ($plan['allowed_entitlements_count'] ?? 0);
                $monthlyPrice = (float) ($plan['price_usd_monthly'] ?? 0);
                $yearlyPrice = (float) ($plan['price_usd_yearly'] ?? 0);
                $features = (array) ($plan['features'] ?? []);
                $cta = $plan['cta'] ?? 'Get Started';
            @endphp
            <div class="col-lg-3 col-md-6">
                <article class="price-card glass-card reveal {{ $isFeatured ? 'recommended' : '' }}">
                    @if($isFeatured)
                        <span class="price-ribbon">{{ LandingContent::text('pricing_page.featured_label') }}</span>
                    @elseif(! empty($badge))
                        <span class="price-ribbon">{{ __($badge) }}</span>
                    @endif

                    <h3>{{ __($title) }}</h3>
                    <p class="text-muted-soft mb-0">
                        {{ __($summary ?: 'Includes :credits monthly credits and :actions AI actions.', ['credits' => number_format($monthlyCredits), 'actions' => $allowedActions]) }}
                    </p>

                    <div class="plan-price">
                        <span>$</span>
                        <span
                            data-monthly="{{ rtrim(rtrim(number_format($monthlyPrice, 2, '.', ''), '0'), '.') }}"
                            data-yearly="{{ rtrim(rtrim(number_format($yearlyPrice, 2, '.', ''), '0'), '.') }}"
                        >
                            {{ rtrim(rtrim(number_format($monthlyPrice, 2, '.', ''), '0'), '.') }}
                        </span>
                        <small
                            data-period
                            data-period-monthly="{{ LandingContent::text('/month') }}"
                            data-period-yearly="{{ LandingContent::text('/year') }}"
                        >
                            {{ LandingContent::text('/month') }}
                        </small>
                    </div>

                    <p
                        class="text-muted-soft small"
                        data-billing-note
                        data-billing-note-monthly="{{ LandingContent::text('pricing_page.monthly_note') }}"
                        data-billing-note-yearly="{{ LandingContent::text('pricing_page.yearly_note') }}"
                    >
                        {{ LandingContent::text('pricing_page.monthly_note') }}
                    </p>

                    {{-- <div class="d-flex flex-wrap gap-2 mt-3">
                        <span class="kbd-soft">{{ LandingContent::text('common.credits_short', ['value' => number_format($monthlyCredits)]) }}</span>
                        <span class="badge-soft">{{ LandingContent::text('common.actions_short', ['value' => $allowedActions]) }}</span>
                    </div> --}}

                    <ul class="check-list">
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ LandingContent::text('common.monthly_credits_value', ['value' => number_format($monthlyCredits)]) }}</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ LandingContent::text('common.included_actions_value', ['value' => $allowedActions]) }}</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ LandingContent::text('common.included_actions_value', ['value' => $allowedActions]) }}</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ LandingContent::text('common.included_actions_value', ['value' => $allowedActions]) }}</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ LandingContent::text('common.included_actions_value', ['value' => $allowedActions]) }}</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ LandingContent::text('common.included_actions_value', ['value' => $allowedActions]) }}</span>
                        </li>
                        @foreach($features as $feature)
                            <li>
                                <i class="bi bi-check-circle-fill"></i>
                                <span>{{ __($feature) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a class="btn {{ $isFeatured ? 'btn-glow' : 'btn-outline-soft' }} w-100 mt-4" href="{{ $ctaHref }}" wire:navigate.hover>
                        {{ __($cta) }}
                    </a>
                </article>
            </div>
        @endforeach
    </div>
</div>

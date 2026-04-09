<?php

use App\Services\Billing\BillingCurrencyService;
use App\Support\LandingContent;
use App\Support\LandingPricingCatalog;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $limit = 0;
    public bool $showToggle = true;
    public bool $showAncillarySections = true;
    public string $switchStyle = 'toggle';
    public array $codes = [];

    #[Computed]
    public function displayContext(): array
    {
        return app(BillingCurrencyService::class)->resolveDisplayContext(
            null,
            ['ip' => request()->ip()]
        );
    }

    #[Computed]
    public function plansCatalog(): array
    {
        return app(LandingPricingCatalog::class)->servicePlans();
    }

    #[Computed]
    public function storageCatalog(): array
    {
        return app(LandingPricingCatalog::class)->storagePlans();
    }

    #[Computed]
    public function addonsCatalog(): array
    {
        return app(LandingPricingCatalog::class)->creditProducts();
    }

    #[Computed]
    public function plans(): array
    {
        $currency = app(BillingCurrencyService::class);
        $context = $this->pricingContext();

        $plans = collect($this->plansCatalog)
            ->map(function (array $plan) use ($currency, $context) {
                $plan['display_monthly'] = $currency->priceDataForBaseAmountIqd(
                    (int) ($plan['price_iqd_monthly'] ?? 0),
                    null,
                    $context
                );
                $plan['display_yearly'] = $currency->priceDataForBaseAmountIqd(
                    (int) ($plan['price_iqd_yearly'] ?? 0),
                    null,
                    $context
                );
                $plan['features'] = $this->normalizeFeatureList($plan['features'] ?? []);

                return $plan;
            })
            ->values();

        if (! empty($this->codes)) {
            $codeOrder = array_values(array_filter(array_map('strtolower', $this->codes)));

            $plans = collect($codeOrder)
                ->map(fn (string $code) => $plans->firstWhere('code', $code))
                ->filter()
                ->values();
        }

        if ($this->limit > 0) {
            $plans = $plans->take($this->limit)->values();
        }

        return $plans->all();
    }

    #[Computed]
    public function storagePlans(): array
    {
        $currency = app(BillingCurrencyService::class);
        $context = $this->pricingContext();

        return collect($this->storageCatalog)
            ->map(function (array $plan) use ($currency, $context) {
                $plan['display_price'] = $currency->priceDataForBaseAmountIqd(
                    (int) ($plan['price_iqd'] ?? 0),
                    null,
                    $context
                );
                $plan['features'] = $this->normalizeFeatureList($plan['features'] ?? []);

                return $plan;
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function addons(): array
    {
        $currency = app(BillingCurrencyService::class);
        $context = $this->pricingContext();

        return collect($this->addonsCatalog)
            ->map(function (array $product) use ($currency, $context) {
                $product['display_price'] = $currency->priceDataForBaseAmountIqd(
                    (int) ($product['price_iqd'] ?? 0),
                    null,
                    $context
                );
                $product['features'] = $this->normalizeFeatureList($product['features'] ?? []);

                return $product;
            })
            ->values()
            ->all();
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

    protected function pricingContext(): array
    {
        return [
            'display_currency_code' => $this->displayContext['currency_code'] ?? BillingCurrencyService::BASE_CURRENCY,
            'country_code' => $this->displayContext['country_code'] ?? null,
            'ip' => request()->ip(),
        ];
    }

    protected function normalizeFeatureList(array $features): array
    {
        return collect(Arr::wrap($features))
            ->map(fn ($feature) => is_string($feature) ? trim($feature) : '')
            ->filter(fn (string $feature) => $feature !== '')
            ->unique()
            ->values()
            ->all();
    }
};
?>

@php
    $ctaHref = $this->ctaHref();
    $resolvedDisplayCurrency = (string) ($this->displayContext['currency_code'] ?? 'IQD');
    $isRtl = in_array(app()->getLocale(), ['ar', 'ku'], true);
@endphp

<div
    data-pricing-root
    class="pricing-grid-shell {{ $isRtl ? 'pricing-grid-rtl' : 'pricing-grid-ltr' }}"
    dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
>
    @if($showToggle)
        <div class="d-flex justify-content-center mb-5">
            <div class="d-flex justify-content-center align-items-center gap-3 pricing-switch reveal {{ $isRtl ? 'pricing-switch-rtl' : '' }}">
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

    <div class="text-center text-muted-soft small mb-4">
        {{ __('Pricing display resolved for: :currency', ['currency' => $resolvedDisplayCurrency]) }}
        @if($resolvedDisplayCurrency !== 'IQD')
            <span>{{ __('Billing source remains IQD.') }}</span>
        @endif
    </div>

    <div class="row g-4 justify-content-center">
        @foreach($this->plans as $plan)
            @php
                $isFeatured = (bool) ($plan['featured'] ?? false);
                $badge = $plan['badge'] ?? null;
                $title = $plan['title'] ?? $plan['name'] ?? '';
                $summary = $plan['summary'] ?? null;
                $monthlyCredits = (int) ($plan['monthly_credits'] ?? 0);
                $allowedActions = (int) ($plan['allowed_entitlements_count'] ?? 0);
                $monthlyPrice = (string) data_get($plan, 'display_monthly.display_label', data_get($plan, 'display_monthly.iqd_label', 'IQD 0'));
                $yearlyPrice = (string) data_get($plan, 'display_yearly.display_label', data_get($plan, 'display_yearly.iqd_label', 'IQD 0'));
                $showBaseNote = (bool) data_get($plan, 'display_monthly.has_localized_estimate', false)
                    || (bool) data_get($plan, 'display_yearly.has_localized_estimate', false);
                $monthlyBase = (string) data_get($plan, 'display_monthly.iqd_label', 'IQD 0');
                $yearlyBase = (string) data_get($plan, 'display_yearly.iqd_label', 'IQD 0');
                $features = (array) ($plan['features'] ?? []);
                $cta = $plan['cta'] ?? 'Get Started';
            @endphp
            <div class="col-lg-3 col-md-6 d-flex">
                <article class="price-card glass-card reveal h-100 d-flex flex-column {{ $isFeatured ? 'recommended' : '' }}">
                    @if($isFeatured)
                        <span class="price-ribbon">{{ LandingContent::text('pricing_page.featured_label') }}</span>
                    @elseif(! empty($badge))
                        <span class="price-ribbon">{{ __($badge) }}</span>
                    @endif

                    <div class="pricing-card-copy">
                        <h3 class="pricing-card-title">{{ __($title) }}</h3>
                        <p class="text-muted-soft mb-0 pricing-card-summary">
                            {{ __($summary ?: 'Includes :credits monthly credits and :actions enabled actions.', ['credits' => number_format($monthlyCredits), 'actions' => $allowedActions]) }}
                        </p>
                    </div>

                    <div class="pricing-card-price-block">
                        <div class="plan-price">
                            <span class="pricing-plan-amount" data-monthly="{{ $monthlyPrice }}" data-yearly="{{ $yearlyPrice }}">
                                {{ $monthlyPrice }}
                            </span>
                            <small
                                class="pricing-plan-period"
                                data-period
                                data-period-monthly="{{ LandingContent::text('/month') }}"
                                data-period-yearly="{{ LandingContent::text('/year') }}"
                            >
                                {{ LandingContent::text('/month') }}
                            </small>
                        </div>

                        <div class="pricing-card-meta">
                            <p
                                class="text-muted-soft small mb-0"
                                data-billing-note
                                data-billing-note-monthly="{{ LandingContent::text('pricing_page.monthly_note') }}"
                                data-billing-note-yearly="{{ LandingContent::text('pricing_page.yearly_note') }}"
                            >
                                {{ LandingContent::text('pricing_page.monthly_note') }}
                            </p>

                            {{-- @if($showBaseNote)
                                <p
                                    class="text-muted-soft small mb-0"
                                    data-monthly="{{ __('Base billing: :amount', ['amount' => $monthlyBase]) }}"
                                    data-yearly="{{ __('Base billing: :amount', ['amount' => $yearlyBase]) }}"
                                >
                                    {{ __('Base billing: :amount', ['amount' => $monthlyBase]) }}
                                </p>
                            @endif --}}
                        </div>
                    </div>

                    <ul class="check-list mt-4 pricing-card-features flex-grow-1">
                        @foreach($features as $feature)
                            <li class="pricing-feature-item">
                                <i class="bi bi-check-circle-fill pricing-feature-icon"></i>
                                <span class="pricing-feature-text">{{ __($feature) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="pricing-card-actions mt-auto pt-4">
                        <a class="btn {{ $isFeatured ? 'btn-glow' : 'btn-outline-soft' }} w-100" href="{{ $ctaHref }}" wire:navigate>
                            {{ __($cta) }}
                        </a>
                    </div>
                </article>
            </div>
        @endforeach
    </div>

    @if($showAncillarySections && ($this->storagePlans || $this->addons))
        <div class="pricing-secondary mt-5 pt-4">
            @if($this->storagePlans)
                <div class="text-center mb-4 reveal">
                    <span class="section-badge mb-3">
                        <i class="bi bi-hdd-stack"></i>
                        {{ __('Storage Pricing') }}
                    </span>
                    <h3 class="section-title h2">{{ __('Storage upgrades') }}</h3>
                    <p class="lead-soft mx-auto">{{ __('Flexible storage packs priced from the same canonical IQD billing catalog.') }}</p>
                </div>

                <div class="row g-4 justify-content-center mb-5">
                    @foreach($this->storagePlans as $plan)
                        @php
                            $priceLabel = (string) data_get($plan, 'display_price.display_label', data_get($plan, 'display_price.iqd_label', 'IQD 0'));
                            $baseLabel = (string) data_get($plan, 'display_price.iqd_label', 'IQD 0');
                            $features = (array) ($plan['features'] ?? []);
                        @endphp
                        <div class="col-lg-4 col-md-6 d-flex">
                            <article class="price-card glass-card reveal h-100 d-flex flex-column">
                                <div class="pricing-card-copy">
                                    <h3 class="pricing-card-title">{{ $plan['title'] }}</h3>
                                    <p class="text-muted-soft mb-0 pricing-card-summary">{{ $plan['summary'] }}</p>
                                </div>
                                <div class="pricing-card-price-block">
                                    <div class="plan-price">
                                        <span class="pricing-plan-amount">{{ $priceLabel }}</span>
                                        <small class="pricing-plan-period">{{ __('one-time') }}</small>
                                    </div>
                                    <div class="pricing-card-meta">
                                        @if((bool) data_get($plan, 'display_price.has_localized_estimate', false))
                                            <p class="text-muted-soft small mb-0">{{ __('Base billing: :amount', ['amount' => $baseLabel]) }}</p>
                                        @endif
                                    </div>
                                </div>
                                <ul class="check-list mt-4 pricing-card-features flex-grow-1">
                                    @foreach($features as $feature)
                                        <li class="pricing-feature-item">
                                            <i class="bi bi-check-circle-fill pricing-feature-icon"></i>
                                            <span class="pricing-feature-text">{{ __($feature) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="pricing-card-actions mt-auto pt-4">
                                    <a class="btn btn-outline-soft w-100" href="{{ $ctaHref }}" wire:navigate>
                                        {{ __('Get Started') }}
                                    </a>
                                </div>
                            </article>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($this->addons)
                <div class="text-center mb-4 reveal">
                    <span class="section-badge mb-3">
                        <i class="bi bi-lightning-charge"></i>
                        {{ __('Add-on Pricing') }}
                    </span>
                    <h3 class="section-title h2">{{ __('Credit top-ups') }}</h3>
                    <p class="lead-soft mx-auto">{{ __('One-time add-on packs for extra credits whenever your team needs more throughput.') }}</p>
                </div>

                <div class="row g-4 justify-content-center">
                    @foreach($this->addons as $product)
                        @php
                            $priceLabel = (string) data_get($product, 'display_price.display_label', data_get($product, 'display_price.iqd_label', 'IQD 0'));
                            $baseLabel = (string) data_get($product, 'display_price.iqd_label', 'IQD 0');
                            $features = (array) ($product['features'] ?? []);
                            $badge = $product['badge'] ?? null;
                        @endphp
                        <div class="col-lg-4 col-md-6 d-flex">
                            <article class="price-card glass-card reveal h-100 d-flex flex-column">
                                @if(! empty($badge))
                                    <span class="price-ribbon">{{ __($badge) }}</span>
                                @endif
                                <div class="pricing-card-copy">
                                    <h3 class="pricing-card-title">{{ $product['title'] }}</h3>
                                    <p class="text-muted-soft mb-0 pricing-card-summary">{{ $product['summary'] }}</p>
                                </div>
                                <div class="pricing-card-price-block">
                                    <div class="plan-price">
                                        <span class="pricing-plan-amount">{{ $priceLabel }}</span>
                                        <small class="pricing-plan-period">{{ __('one-time') }}</small>
                                    </div>
                                    <div class="pricing-card-meta">
                                        @if((bool) data_get($product, 'display_price.has_localized_estimate', false))
                                            <p class="text-muted-soft small mb-0">{{ __('Base billing: :amount', ['amount' => $baseLabel]) }}</p>
                                        @endif
                                    </div>
                                </div>
                                <ul class="check-list mt-4 pricing-card-features flex-grow-1">
                                    @foreach($features as $feature)
                                        <li class="pricing-feature-item">
                                            <i class="bi bi-check-circle-fill pricing-feature-icon"></i>
                                            <span class="pricing-feature-text">{{ __($feature) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="pricing-card-actions mt-auto pt-4">
                                    <a class="btn btn-outline-soft w-100" href="{{ $ctaHref }}" wire:navigate>
                                        {{ __('Get Started') }}
                                    </a>
                                </div>
                            </article>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>

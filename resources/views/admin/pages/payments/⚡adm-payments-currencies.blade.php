<?php

use App\Models\CountryCurrencyMap;
use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Services\Billing\BillingCurrencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use \App\Support\Admin\SecureAdminComponent;

    public string $search = '';


    #[\Livewire\Attributes\Locked]
    public ?string $editingCurrencyCode = null;
    public ?int $editingRateId = null;

    public string $quoteCurrencyCode = '';
    public string $rate = '';
    public string $source = 'manual';
    public string $effectiveAt = '';
    public string $expiresAt = '';
    public bool $isActive = true;
    public int $decimalPlaces = 2;
    public string $roundingStep = '0.25';
    public string $roundingMode = 'nearest';
    public string $localeHint = 'en_US';

    public function mount(): void
    {
        $this->effectiveAt = now()->format('Y-m-d\TH:i');
    }

    #[Computed]
    public function currencySchema(): array
    {
        return Schema::getColumnListing('currencies');
    }

    protected function currenciesHaveRoundingColumns(): bool
    {
        return in_array('rounding_step', $this->currencySchema, true)
            && in_array('rounding_mode', $this->currencySchema, true);
    }

    protected function currenciesHaveLocaleHintColumn(): bool
    {
        return in_array('locale_hint', $this->currencySchema, true);
    }

    #[Computed]
    public function baseCurrencyCode(): string
    {
        return app(BillingCurrencyService::class)->baseCurrencyCode();
    }

    #[Computed]
    public function secondaryCurrencyCode(): string
    {
        return app(BillingCurrencyService::class)->secondaryCurrencyCode();
    }

    protected function managedBaseCurrencyForQuote(?string $quoteCurrencyCode = null): string
    {
        return app(BillingCurrencyService::class)->managedBaseCurrencyForQuote(
            (string) ($quoteCurrencyCode ?: $this->quoteCurrencyCode)
        );
    }

    protected function selectedRatePairLabel(): string
    {
        $quoteCurrencyCode = strtoupper(trim((string) $this->quoteCurrencyCode));

        if ($quoteCurrencyCode === '') {
            return __('Select a quote currency to choose the managed pair.');
        }

        return __('Manual pair: 1 :base = :quote', [
            'base' => $this->managedBaseCurrencyForQuote($quoteCurrencyCode),
            'quote' => $quoteCurrencyCode,
        ]);
    }

    #[Computed]
    public function editableCurrencies(): array
    {
        return Currency::query()
            ->where('code', '!=', $this->baseCurrencyCode)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (Currency $currency) => [$currency->code => "{$currency->code} - {$currency->name}"])
            ->all();
    }

    #[Computed]
    public function currencyRows()
    {
        $search = trim($this->search);
        $currencyService = app(BillingCurrencyService::class);

        $currencies = Currency::query()
            ->where('code', '!=', $this->baseCurrencyCode)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('code', 'like', '%' . strtoupper($search) . '%')
                        ->orWhere('name', 'like', '%' . $search . '%');
                });
            })
            ->with([
                'countryMappings' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('country_code'),
            ])
            ->orderBy('code')
            ->get();
        $currentRates = CurrencyExchangeRate::query()
            ->whereIn('base_currency_code', [$this->baseCurrencyCode, $this->secondaryCurrencyCode])
            ->where('is_current', true)
            ->orderByDesc('effective_at')
            ->get()
            ->unique(fn (CurrencyExchangeRate $rate) => $rate->base_currency_code . ':' . $rate->quote_currency_code)
            ->keyBy(fn (CurrencyExchangeRate $rate) => $rate->base_currency_code . ':' . $rate->quote_currency_code);

        return $currencies->map(function (Currency $currency) use ($currencyService, $currentRates) {
            $managedBase = $this->managedBaseCurrencyForQuote($currency->code);
            /** @var CurrencyExchangeRate|null $currentRate */
            $currentRate = $currentRates->get($managedBase . ':' . $currency->code);
            // Reuse this read's newest persisted rates, including the existing direct fallback.
            $direct = $currentRates->get($this->baseCurrencyCode . ':' . $currency->code)?->rate;
            $bridge = $currentRates->get($this->baseCurrencyCode . ':' . $this->secondaryCurrencyCode)?->rate;
            $cross = $currentRates->get($this->secondaryCurrencyCode . ':' . $currency->code)?->rate;
            $derivedRate = $currency->code === $this->secondaryCurrencyCode ? ($direct !== null ? (float) $direct : null)
                : ($bridge !== null && $cross !== null ? round((float) $bridge * (float) $cross, 12) : ($direct !== null ? (float) $direct : null));

            return [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->decimal_places,
                'rounding_step' => (float) ($this->currenciesHaveRoundingColumns() ? ($currency->rounding_step ?? 0.25) : 0.25),
                'rounding_mode' => (string) ($this->currenciesHaveRoundingColumns() ? ($currency->rounding_mode ?? 'nearest') : 'nearest'),
                'locale_hint' => (string) ($this->currenciesHaveLocaleHintColumn() ? ($currency->locale_hint ?? '') : ''),
                'is_active' => (bool) $currency->is_active,
                'country_codes' => $currency->countryMappings->pluck('country_code')->values()->all(),
                'managed_base_currency_code' => $managedBase,
                'current_rate_id' => $currentRate?->id,
                'current_rate' => $currentRate?->rate !== null ? (float) $currentRate->rate : null,
                'derived_rate' => $derivedRate,
                'current_source' => (string) ($currentRate?->source ?? ''),
                'effective_at' => $currentRate?->effective_at,
                'expires_at' => $currentRate?->expires_at,
                'updated_at' => $currentRate?->updated_at ?? $currency->updated_at,
            ];
        });
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'currencies' => (int) Currency::query()->where('code', '!=', $this->baseCurrencyCode)->count(),
            'active_currencies' => (int) Currency::query()->where('code', '!=', $this->baseCurrencyCode)->where('is_active', true)->count(),
            'current_rates' => (int) CurrencyExchangeRate::query()
                ->whereIn('base_currency_code', [$this->baseCurrencyCode, $this->secondaryCurrencyCode])
                ->where('quote_currency_code', '!=', $this->baseCurrencyCode)
                ->where('is_current', true)
                ->count(),
            'country_maps' => (int) CountryCurrencyMap::query()
                ->where('is_active', true)
                ->count(),
        ];
    }

    protected function formRules(): array
    {
        return [
            'quoteCurrencyCode' => ['required', 'string', Rule::in(array_keys($this->editableCurrencies))],
            'rate' => ['required', 'numeric', 'gt:0'],
            'source' => ['required', 'string', 'max:80'],
            'effectiveAt' => ['required', 'date'],
            'expiresAt' => ['nullable', 'date', 'after_or_equal:effectiveAt'],
            'decimalPlaces' => ['required', 'integer', 'min:0', 'max:6'],
            'roundingStep' => ['required', 'numeric', 'gt:0'],
            'roundingMode' => ['required', 'string', Rule::in(['nearest', 'up', 'down'])],
            'localeHint' => ['nullable', 'string', 'max:24'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->dispatch('payments-currencies:modal-show', id: 'paymentCurrencyModal');
    }

    public function openEditModal(string $currencyCode): void
    {
        $currency = Currency::query()
            ->where('code', strtoupper(trim($currencyCode)))
            ->where('code', '!=', $this->baseCurrencyCode)
            ->firstOrFail();

        $currentRate = CurrencyExchangeRate::query()
            ->where('base_currency_code', $this->managedBaseCurrencyForQuote($currency->code))
            ->where('quote_currency_code', $currency->code)
            ->where('is_current', true)
            ->latest('effective_at')
            ->first();

        $this->editingCurrencyCode = $currency->code;
        $this->editingRateId = $currentRate?->id;
        $this->quoteCurrencyCode = $currency->code;
        $this->rate = $currentRate?->rate !== null ? (string) ((float) $currentRate->rate) : '';
        $this->source = (string) ($currentRate?->source ?? 'manual');
        $this->effectiveAt = ($currentRate?->effective_at ?? now())->format('Y-m-d\TH:i');
        $this->expiresAt = $currentRate?->expires_at?->format('Y-m-d\TH:i') ?? '';
        $this->isActive = (bool) $currency->is_active;
        $this->decimalPlaces = (int) $currency->decimal_places;
        $this->roundingStep = (string) ((float) ($this->currenciesHaveRoundingColumns() ? ($currency->rounding_step ?? 0.25) : 0.25));
        $this->roundingMode = (string) ($this->currenciesHaveRoundingColumns() ? ($currency->rounding_mode ?? 'nearest') : 'nearest');
        $this->localeHint = (string) ($this->currenciesHaveLocaleHintColumn() ? ($currency->locale_hint ?? '') : '');
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-currencies:modal-show', id: 'paymentCurrencyModal');
    }

    public function saveRate(): void
    {
        $this->authorizeAdminChange('admin.pricing');

        $validated = $this->validate($this->formRules());
        $quoteCurrencyCode = strtoupper(trim((string) $validated['quoteCurrencyCode']));

        DB::transaction(function () use ($validated, $quoteCurrencyCode) {
            $currency = Currency::query()
                ->lockForUpdate()
                ->where('code', $quoteCurrencyCode)
                ->where('code', '!=', $this->baseCurrencyCode)
                ->firstOrFail();

            $rateRow = $this->editingRateId
                ? CurrencyExchangeRate::query()->lockForUpdate()->find($this->editingRateId)
                : null;
            if (($this->editingCurrencyCode !== null && $this->editingCurrencyCode !== $quoteCurrencyCode)
                || ($this->editingRateId && (! $rateRow
                    || $rateRow->base_currency_code !== $this->managedBaseCurrencyForQuote($quoteCurrencyCode)
                    || $rateRow->quote_currency_code !== $quoteCurrencyCode))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['rate' => __('admin_p0.currency_target')]);
            }
            $before = ['currency' => $currency->getAttributes(), 'rates' => CurrencyExchangeRate::where('quote_currency_code', $quoteCurrencyCode)->get()->toArray()];
            $currencyPayload = [
                'decimal_places' => (int) $validated['decimalPlaces'],
                'is_active' => (bool) $this->isActive,
            ];

            if ($this->currenciesHaveRoundingColumns()) {
                $currencyPayload['rounding_step'] = (float) $validated['roundingStep'];
                $currencyPayload['rounding_mode'] = (string) $validated['roundingMode'];
            }

            if ($this->currenciesHaveLocaleHintColumn()) {
                $currencyPayload['locale_hint'] = trim((string) ($validated['localeHint'] ?? '')) ?: null;
            }

            $currency->fill($currencyPayload);
            $currency->save();

            CurrencyExchangeRate::query()
                ->where('base_currency_code', $this->managedBaseCurrencyForQuote($quoteCurrencyCode))
                ->where('quote_currency_code', $quoteCurrencyCode)
                ->update(['is_current' => false]);

            if (! $rateRow) {
                $rateRow = new CurrencyExchangeRate();
            }

            $rateRow->fill([
                'base_currency_code' => $this->managedBaseCurrencyForQuote($quoteCurrencyCode),
                'quote_currency_code' => $quoteCurrencyCode,
                'rate' => (float) $validated['rate'],
                'source' => trim((string) $validated['source']),
                'effective_at' => $validated['effectiveAt'],
                'expires_at' => trim((string) ($validated['expiresAt'] ?? '')) !== ''
                    ? $validated['expiresAt']
                    : null,
                'is_current' => (bool) $this->isActive,
            ]);
            $rateRow->save();
            app(\App\Services\Admin\AdminAudit::class)->record('currency.rate', Currency::class, $currency->code,
                $before, $validated, ['currency' => $currency->getAttributes(), 'rate' => $rateRow->getAttributes()]);
        });

        unset($this->currencyRows, $this->topStats, $this->editableCurrencies);

        $this->dispatch('alert', type: 'success', message: __('Currency rate and rounding settings saved successfully.'));
        $this->resetForm();
        $this->dispatch('payments-currencies:modal-hide', id: 'paymentCurrencyModal');
    }

    public function deactivateRate(string $currencyCode): void
    {
        $this->authorizeAdminChange('admin.pricing');

        $currencyCode = strtoupper(trim($currencyCode));
        if ($currencyCode === $this->baseCurrencyCode || ! array_key_exists($currencyCode, $this->editableCurrencies)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['rate' => __('admin_p0.currency_target')]);
        }

        DB::transaction(function () use ($currencyCode) {
            $currency = Currency::query()->lockForUpdate()
                ->where('code', $currencyCode)
                ->where('code', '!=', $this->baseCurrencyCode)->firstOrFail();
            $before = ['currency' => $currency->getAttributes(), 'rates' => CurrencyExchangeRate::where('quote_currency_code', $currencyCode)->get()->toArray()];
            $currency->update(['is_active' => false]);

            CurrencyExchangeRate::query()
                ->where('base_currency_code', $this->managedBaseCurrencyForQuote($currencyCode))
                ->where('quote_currency_code', $currencyCode)
                ->update(['is_current' => false]);
            app(\App\Services\Admin\AdminAudit::class)->record('currency.deactivate', Currency::class, $currencyCode,
                $before, ['is_active' => false], ['currency' => $currency->getAttributes(), 'rates' => CurrencyExchangeRate::where('quote_currency_code', $currencyCode)->get()->toArray()]);
        });

        unset($this->currencyRows, $this->topStats, $this->editableCurrencies);

        $this->dispatch('alert', type: 'success', message: __('Currency display and current rate were deactivated.'));
    }

    public function resetForm(): void
    {
        $this->editingCurrencyCode = null;
        $this->editingRateId = null;
        $this->quoteCurrencyCode = '';
        $this->rate = '';
        $this->source = 'manual';
        $this->effectiveAt = now()->format('Y-m-d\TH:i');
        $this->expiresAt = '';
        $this->isActive = true;
        $this->decimalPlaces = 2;
        $this->roundingStep = '0.25';
        $this->roundingMode = 'nearest';
        $this->localeHint = 'en_US';
        $this->resetErrorBag();
        $this->resetValidation();
    }
};
?>

<x-slot:title>{{ __('Currency Exchange Rates') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Currency Exchange Rates') }}</h4>
                    <p class="text-muted mb-0">{{ __('IQD is the canonical billing currency. Manage quote rates, active display currencies, and pricing-rounding rules here.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <span class="badge bg-primary-subtle text-primary fs-12">{{ __('Base: :currency', ['currency' => $this->baseCurrencyCode]) }}</span>
                    <button type="button" class="btn btn-primary" wire:click="openCreateModal" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('New Rate') }}</button>
                </div>
            </div>
        </div>
    </div>

    @if (!in_array('rounding_step', $this->currencySchema, true) || !in_array('rounding_mode', $this->currencySchema, true))
        <div class="alert alert-warning">
            {{ __('The rounding columns are not available yet in this database. Rates can still be managed, but per-currency rounding settings will stay on fallback defaults until the latest currency migration is applied.') }}
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Currencies') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['currencies']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Supported non-base currencies in the billing catalog.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Active Displays') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['active_currencies']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Currencies currently available for customer or admin display.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Current Rates') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['current_rates']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Currencies with a current IQD quote rate.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Country Maps') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['country_maps']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Active country-to-display-currency mappings.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-6">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-payments-currencies-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search currency code or name...') }}" id="admin-field-adm-payments-currencies-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-6 text-xl-end">
                    <div class="text-muted small">
                        {{ __('Manage IQD -> USD manually, then USD -> target manually. IQD -> other currencies are derived automatically from that chain, while snapshots still keep the exact rate and rounded amount used at checkout time.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-1">{{ __('Rate Table') }}</h5>
                    <p class="text-muted mb-0">{{ __('View the manually managed pair, the derived IQD quote rate, rounding policy, and mapped countries for each supported display currency.') }}</p>
                </div>
                <div class="small text-muted">{{ __(':count currencies matched the current search.', ['count' => $this->currencyRows->count()]) }}</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Currency') }}</th>
                            <th>{{ __('Current Rate') }}</th>
                            <th>{{ __('Rounding') }}</th>
                            <th>{{ __('Decimals') }}</th>
                            <th>{{ __('Countries') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Updated') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->currencyRows as $row)
                            <tr wire:key="payment-currency-{{ $row['code'] }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $row['code'] }} - {{ $row['name'] }}</span>
                                        <span class="text-muted small">{{ __('Managed pair: 1 :base = ? :quote', ['base' => $row['managed_base_currency_code'], 'quote' => $row['code']]) }}</span>
                                        <span class="text-muted small">{{ __('Locale: :value', ['value' => $row['locale_hint'] !== '' ? $row['locale_hint'] : '—']) }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if($row['current_rate'] !== null)
                                        <div class="d-flex flex-column">
                                            <span class="fw-semibold">{{ __('1 :base = :rate :currency', ['base' => $row['managed_base_currency_code'], 'rate' => number_format((float) $row['current_rate'], 8), 'currency' => $row['code']]) }}</span>
                                            @if($row['code'] !== $this->secondaryCurrencyCode && $row['derived_rate'] !== null)
                                                <span class="text-muted small">{{ __('Derived: 1 IQD = :rate :currency', ['rate' => number_format((float) $row['derived_rate'], 8), 'currency' => $row['code']]) }}</span>
                                            @endif
                                            <span class="text-muted small">{{ $row['current_source'] !== '' ? $row['current_source'] : __('manual') }}</span>
                                        </div>
                                    @else
                                        <span class="text-muted">{{ __('No current managed rate') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ __('Step: :step', ['step' => number_format((float) $row['rounding_step'], 4)]) }}</span>
                                        <span class="text-muted small">{{ __('Mode: :mode', ['mode' => ucfirst($row['rounding_mode'])]) }}</span>
                                    </div>
                                </td>
                                <td>{{ number_format((int) $row['decimal_places']) }}</td>
                                <td>
                                    @if (count($row['country_codes']) > 0)
                                        <div class="d-flex flex-wrap gap-1">
                                            @foreach ($row['country_codes'] as $countryCode)
                                                <span class="badge bg-light text-body">{{ $countryCode }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-muted">{{ __('No mapped countries') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $row['is_active'] ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                        {{ $row['is_active'] ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td>
                                    <span class="text-muted small">
                                        {{ $row['updated_at'] ? \Illuminate\Support\Carbon::parse($row['updated_at'])->diffForHumans() : __('Never') }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-primary" wire:click="openEditModal('{{ $row['code'] }}')" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Edit') }}</button>
                                        <button type="button" class="btn btn-sm btn-soft-danger" data-admin-method="deactivateRate" data-admin-args="{{ json_encode([$row['code']]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>{{ __('Deactivate') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">{{ __('No currencies matched the current search.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div wire:ignore.self class="modal fade" id="paymentCurrencyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1">{{ $editingCurrencyCode ? __('Edit Exchange Rate') : __('Create Exchange Rate') }}</h5>
                        <p class="text-muted mb-0">{{ __('Manage the manual anchor pair and per-currency rounding. USD is the secondary control layer; other IQD rates are derived automatically.') }}</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" wire:click="resetForm"></button>
                </div>
                <form data-admin-method="saveRate" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.pricing') }}">
<fieldset @if(! \App\Support\Admin\AdminUiAccess::can('admin.pricing')) disabled @endif>
                    @csrf
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-currencies-2">{{ __('Quote Currency') }}</label>
                                <select class="form-select @error('quoteCurrencyCode') is-invalid @enderror" wire:model.live="quoteCurrencyCode" data-admin-review id="admin-field-adm-payments-currencies-2">
                                    <option value="">{{ __('Select currency') }}</option>
                                    @foreach ($this->editableCurrencies as $currencyCode => $currencyLabel)
                                        <option value="{{ $currencyCode }}">{{ $currencyLabel }}</option>
                                    @endforeach
                                </select>
                                @error('quoteCurrencyCode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">{{ $this->selectedRatePairLabel() }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin-field-adm-payments-currencies-3">
                                    {{ __('Rate (1 :base = ? :quote)', ['base' => $this->managedBaseCurrencyForQuote(), 'quote' => $quoteCurrencyCode !== '' ? $quoteCurrencyCode : __('currency')]) }}
                                </label>
                                <input type="number" min="0" step="0.00000001" class="form-control @error('rate') is-invalid @enderror" wire:model.live.debounce.250ms="rate" id="admin-field-adm-payments-currencies-3">
                                @error('rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @if ($quoteCurrencyCode !== '')
                                <div class="col-12">
                                    <div class="alert alert-info mb-0">
                                        @if ($quoteCurrencyCode === $this->secondaryCurrencyCode)
                                            {{ __('This is the primary control pair. All derived non-IQD display currencies depend on the current IQD -> USD rate.') }}
                                        @else
                                            {{ __('This manual rate is stored as 1 USD = ? :currency. The system derives 1 IQD = ? :currency automatically from IQD -> USD x USD -> :currency.', ['currency' => $quoteCurrencyCode]) }}
                                        @endif
                                    </div>
                                </div>
                            @endif
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-currencies-4">{{ __('Source') }}</label>
                                <input type="text" class="form-control @error('source') is-invalid @enderror" wire:model.defer="source" placeholder="{{ __('manual') }}" id="admin-field-adm-payments-currencies-4">
                                @error('source') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-currencies-5">{{ __('Effective At') }}</label>
                                <input type="datetime-local" class="form-control @error('effectiveAt') is-invalid @enderror" wire:model.defer="effectiveAt" id="admin-field-adm-payments-currencies-5">
                                @error('effectiveAt') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="admin-field-adm-payments-currencies-6">{{ __('Expires At') }}</label>
                                <input type="datetime-local" class="form-control @error('expiresAt') is-invalid @enderror" wire:model.defer="expiresAt" id="admin-field-adm-payments-currencies-6">
                                @error('expiresAt') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-payments-currencies-7">{{ __('Decimal Places') }}</label>
                                <input type="number" min="0" max="6" class="form-control @error('decimalPlaces') is-invalid @enderror" wire:model.defer="decimalPlaces" id="admin-field-adm-payments-currencies-7">
                                @error('decimalPlaces') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-payments-currencies-8">{{ __('Rounding Step') }}</label>
                                <input type="number" min="0.0001" step="0.0001" class="form-control @error('roundingStep') is-invalid @enderror" wire:model.defer="roundingStep" id="admin-field-adm-payments-currencies-8">
                                @error('roundingStep') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-payments-currencies-9">{{ __('Rounding Mode') }}</label>
                                <select class="form-select @error('roundingMode') is-invalid @enderror" wire:model.defer="roundingMode" id="admin-field-adm-payments-currencies-9">
                                    <option value="nearest">{{ __('Nearest') }}</option>
                                    <option value="up">{{ __('Up') }}</option>
                                    <option value="down">{{ __('Down') }}</option>
                                </select>
                                @error('roundingMode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="admin-field-adm-payments-currencies-10">{{ __('Locale Hint') }}</label>
                                <input type="text" class="form-control @error('localeHint') is-invalid @enderror" wire:model.defer="localeHint" placeholder="{{ __('en_US') }}" id="admin-field-adm-payments-currencies-10">
                                @error('localeHint') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="currencyIsActive" wire:model.defer="isActive">
                                    <label class="form-check-label" for="currencyIsActive">{{ __('Active for display and mark this rate as current') }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" wire:click="resetForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingCurrencyCode ? __('Save Changes') : __('Create Rate') }}</button>
                    </div>
                </fieldset></form>
            </div>
        </div>
    </div>

    @push('scripts')
        @once
            <script>
                (() => {
                    if (window.__PAYMENT_CURRENCIES_MODAL_EVENTS__) {
                        return;
                    }

                    window.__PAYMENT_CURRENCIES_MODAL_EVENTS__ = true;

                    const cleanupModalState = () => {
                        if (typeof bootstrap === 'undefined') {
                            return;
                        }

                        const element = document.getElementById('paymentCurrencyModal');

                        if (!element) {
                            return;
                        }

                        const instance = bootstrap.Modal.getInstance(element);

                        if (instance) {
                            instance.hide();
                            instance.dispose();
                        }

                        element.classList.remove('show');
                        element.style.display = 'none';
                        element.removeAttribute('aria-modal');
                        element.removeAttribute('role');

                        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                        document.body.classList.remove('modal-open');
                        document.body.style.removeProperty('padding-right');
                        document.body.style.removeProperty('overflow');
                    };

                    const withModal = (id, callback) => {
                        if (!id || typeof bootstrap === 'undefined') {
                            return;
                        }

                        const element = document.getElementById(id);

                        if (!element) {
                            return;
                        }

                        callback(bootstrap.Modal.getOrCreateInstance(element));
                    };

                    window.addEventListener('payments-currencies:modal-show', (event) => {
                        withModal(event.detail?.id, (modal) => modal.show());
                    });

                    window.addEventListener('payments-currencies:modal-hide', (event) => {
                        withModal(event.detail?.id, (modal) => modal.hide());
                    });

                    document.addEventListener('livewire:navigating', cleanupModalState);
                    document.addEventListener('livewire:navigated', cleanupModalState);

                    cleanupModalState();
                })();
            </script>
        @endonce
    @endpush
</div>

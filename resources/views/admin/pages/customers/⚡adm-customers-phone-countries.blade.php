<?php

use App\Models\RegistrationPhoneCountry;
use App\Support\RegistrationPhoneCountryManager;
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

    public array $enabledCountries = [];

    public function mount(): void
    {
        $this->enabledCountries = $this->registrationCountriesTableExists()
            ? RegistrationPhoneCountry::query()
                ->where('is_enabled', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('iso2')
                ->map(static fn (string $iso2): string => strtolower($iso2))
                ->values()
                ->all()
            : RegistrationPhoneCountryManager::defaultEnabledCountryCodes();
    }

    #[Computed]
    public function registrationCountries()
    {
        if (! $this->registrationCountriesTableExists()) {
            return collect();
        }

        $query = RegistrationPhoneCountry::query()
            ->orderByDesc('is_enabled')
            ->orderBy('sort_order')
            ->orderBy('name');

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('iso2', 'like', '%' . strtolower($search) . '%');
            });
        }

        return $query->get();
    }

    #[Computed]
    public function topStats(): array
    {
        if (! $this->registrationCountriesTableExists()) {
            return [
                'total' => 0,
                'enabled' => count($this->enabledCountries),
                'filtered' => 0,
            ];
        }

        return [
            'total' => (int) RegistrationPhoneCountry::query()->count(),
            'enabled' => count($this->enabledCountries),
            'filtered' => (int) $this->registrationCountries->count(),
        ];
    }

    public function enableDefaults(): void
    {
        $this->enabledCountries = RegistrationPhoneCountryManager::defaultEnabledCountryCodes();
    }

    public function enableAll(): void
    {
        if (! $this->registrationCountriesTableExists()) {
            return;
        }

        $this->enabledCountries = RegistrationPhoneCountry::query()
            ->orderBy('name')
            ->pluck('iso2')
            ->map(static fn (string $iso2): string => strtolower($iso2))
            ->values()
            ->all();
    }

    public function clearAll(): void
    {
        $this->enabledCountries = [];
    }

    public function save(): void
    {
        $this->authorizeAdminChange('admin.customers');

        if (! $this->registrationCountriesTableExists()) {
            $this->dispatch('alert', type: 'error', message: __('Run the migration for registration phone countries before using this page.'));
            return;
        }

        $allowedIso2 = RegistrationPhoneCountry::query()->pluck('iso2')->all();

        $validated = $this->validate([
            'enabledCountries' => ['required', 'array', 'min:1'],
            'enabledCountries.*' => ['required', 'string', Rule::in($allowedIso2)],
        ], [
            'enabledCountries.required' => __('Please enable at least one country.'),
            'enabledCountries.min' => __('Please enable at least one country.'),
        ]);

        $enabledCountries = collect($validated['enabledCountries'])
            ->map(static fn (string $iso2): string => strtolower($iso2))
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($enabledCountries) {
            RegistrationPhoneCountry::query()->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);

            RegistrationPhoneCountry::query()
                ->whereIn('iso2', $enabledCountries)
                ->update([
                    'is_enabled' => true,
                    'updated_at' => now(),
                ]);
        });

        $this->enabledCountries = RegistrationPhoneCountry::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('iso2')
            ->map(static fn (string $iso2): string => strtolower($iso2))
            ->values()
            ->all();

        unset($this->topStats, $this->registrationCountries);

        $this->dispatch('alert', type: 'success', message: __('Allowed registration countries updated.'));
    }

    private function registrationCountriesTableExists(): bool
    {
        return Schema::hasTable('registration_phone_countries');
    }
};
?>

<x-slot:title>{{ __('Phone Registration Countries') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <x-admin-change-reason />
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Phone Registration Countries') }}</h4>
                    <p class="text-muted mb-0">{{ __('Choose which countries are allowed to sign up or update their phone number in the app.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">{{ __('Back to Customers') }}</a>
                    <button type="button" class="btn btn-soft-warning" wire:click="enableDefaults">{{ __('Restore Defaults') }}</button>
                    <button type="button" class="btn btn-primary" wire:click="save">{{ __('Save Changes') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-4 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Total Countries') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['total']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Country rows available in the registration country catalog.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Enabled Countries') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['enabled']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Only these countries will appear in the phone registration picker.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Visible Results') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['filtered']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Countries matching the current search filter.') }}</p>
                </div>
            </div>
        </div>
    </div>

    @if (!\Illuminate\Support\Facades\Schema::hasTable('registration_phone_countries'))
        <div class="card">
            <div class="card-body">
                <div class="alert alert-warning mb-0">
                    {{ __('The new registration country table is not available yet. Run the migration to manage countries from admin.') }}
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-header border-0">
                <div class="row g-3 align-items-end">
                    <div class="col-xl-6">
                        <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                        <div class="search-box">
                            <input type="text" class="form-control" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search country name or ISO2 code...') }}">
                            <i class="ri-search-line search-icon"></i>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="d-flex flex-wrap gap-2 justify-content-xl-end">
                            <button type="button" class="btn btn-soft-success" wire:click="enableAll">{{ __('Enable All') }}</button>
                            <button type="button" class="btn btn-soft-danger" wire:click="clearAll">{{ __('Clear All') }}</button>
                            <button type="button" class="btn btn-soft-warning" wire:click="enableDefaults">{{ __('Requested 6') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form wire:submit.prevent="save">
            <div class="card">
                <div class="card-header border-0">
                    <div>
                        <h5 class="card-title mb-1">{{ __('Allowed Countries') }}</h5>
                        <p class="text-muted mb-0">{{ __('Check the countries that should be available in the registration phone dropdown.') }}</p>
                    </div>
                </div>
                <div class="card-body">
                    @error('enabledCountries')
                        <div class="alert alert-danger">{{ $message }}</div>
                    @enderror

                    <div class="row g-3">
                        @forelse ($this->registrationCountries as $country)
                            <div class="col-12 col-md-6 col-xl-4" wire:key="registration-country-{{ $country->iso2 }}">
                                <label class="border rounded p-3 d-flex align-items-start gap-3 h-100 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        class="form-check-input mt-1"
                                        wire:model="enabledCountries"
                                        value="{{ strtolower($country->iso2) }}"
                                    >
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">{{ $country->name }}</div>
                                        <div class="text-muted small">{{ strtoupper($country->iso2) }}</div>
                                    </div>
                                    <span class="badge {{ in_array(strtolower($country->iso2), $enabledCountries, true) ? 'bg-success-subtle text-success' : 'bg-light text-body' }}">
                                        {{ in_array(strtolower($country->iso2), $enabledCountries, true) ? __('Enabled') : __('Hidden') }}
                                    </span>
                                </label>
                            </div>
                        @empty
                            <div class="col-12">
                                <div class="text-center py-5 text-muted">{{ __('No countries matched the current search.') }}</div>
                            </div>
                        @endforelse
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <small class="text-muted">{{ __('Enabled countries update both signup and phone OTP edit flows.') }}</small>
                    <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
                </div>
            </div>
        </form>
    @endif
</div>

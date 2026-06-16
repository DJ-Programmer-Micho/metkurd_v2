<?php

namespace App\Support;

use App\Models\RegistrationPhoneCountry;
use Illuminate\Support\Facades\Schema;

class RegistrationPhoneCountryManager
{
    public static function defaultCountries(): array
    {
        return [
            ['iso2' => 'iq', 'name' => 'Iraq'],
            ['iso2' => 'de', 'name' => 'Germany'],
            ['iso2' => 'fr', 'name' => 'France'],
            ['iso2' => 'us', 'name' => 'United States'],
            ['iso2' => 'gb', 'name' => 'United Kingdom'],
            ['iso2' => 'tr', 'name' => 'Turkey'],
        ];
    }

    public static function defaultEnabledCountryCodes(): array
    {
        return array_map(
            static fn (array $country): string => $country['iso2'],
            self::defaultCountries()
        );
    }

    public static function enabledCountries(): array
    {
        if (! Schema::hasTable('registration_phone_countries')) {
            return self::defaultCountries();
        }

        $countries = RegistrationPhoneCountry::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['iso2', 'name']);

        if ($countries->isEmpty()) {
            return self::defaultCountries();
        }

        return $countries
            ->map(static fn (RegistrationPhoneCountry $country): array => [
                'iso2' => strtolower((string) $country->iso2),
                'name' => (string) $country->name,
            ])
            ->values()
            ->all();
    }

    public static function enabledCountryCodes(): array
    {
        return array_map(
            static fn (array $country): string => $country['iso2'],
            self::enabledCountries()
        );
    }

    public static function enabledCountryNames(): array
    {
        return array_map(
            static fn (array $country): string => $country['name'],
            self::enabledCountries()
        );
    }

    public static function enabledCountrySummary(int $fullListThreshold = 8): string
    {
        $names = self::enabledCountryNames();

        if ($names === []) {
            return __('No countries enabled');
        }

        if (count($names) <= $fullListThreshold) {
            return implode(', ', $names);
        }

        return __(':count countries', ['count' => count($names)]);
    }

    public static function normalizeIso2(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return preg_match('/^[a-z]{2}$/', $normalized) ? $normalized : '';
    }

    public static function normalizeDialCode(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    public static function isCountryAllowed(?string $iso2): bool
    {
        $normalized = self::normalizeIso2($iso2);

        return $normalized !== '' && in_array($normalized, self::enabledCountryCodes(), true);
    }

    public static function matchesDialCode(?string $phone, ?string $dialCode): bool
    {
        $normalizedPhone = trim((string) $phone);
        $normalizedDialCode = self::normalizeDialCode($dialCode);

        return $normalizedPhone !== ''
            && $normalizedDialCode !== ''
            && str_starts_with($normalizedPhone, '+'.$normalizedDialCode);
    }
}

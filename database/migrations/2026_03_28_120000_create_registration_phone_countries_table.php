<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_phone_countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->string('name', 120);
            $table->boolean('is_enabled')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(999);
            $table->timestamps();
        });

        $defaultCountryNames = [
            'iq' => 'Iraq',
            'de' => 'Germany',
            'fr' => 'France',
            'us' => 'United States',
            'gb' => 'United Kingdom',
            'tr' => 'Turkey',
        ];

        $defaultCountryCodes = array_keys($defaultCountryNames);
        $sortOrderLookup = array_flip($defaultCountryCodes);
        $excludedIso2 = ['eu', 'ez', 'un', 'xa', 'xb', 'zz', 'qo'];

        $countries = [];

        if (class_exists(ResourceBundle::class)) {
            $bundle = ResourceBundle::create('en', 'ICUDATA-region');
            $countryBundle = $bundle ? $bundle['Countries'] : null;

            if ($countryBundle instanceof Traversable || is_array($countryBundle)) {
                foreach ($countryBundle as $code => $name) {
                    $iso2 = strtolower((string) $code);

                    if (! preg_match('/^[a-z]{2}$/', $iso2)) {
                        continue;
                    }

                    if (in_array($iso2, $excludedIso2, true)) {
                        continue;
                    }

                    $countries[$iso2] = (string) $name;
                }
            }
        }

        foreach ($defaultCountryNames as $iso2 => $name) {
            $countries[$iso2] = $countries[$iso2] ?? $name;
        }

        ksort($countries);

        $now = now();
        $rows = [];

        foreach ($countries as $iso2 => $name) {
            $rows[] = [
                'iso2' => $iso2,
                'name' => $name,
                'is_enabled' => in_array($iso2, $defaultCountryCodes, true),
                'sort_order' => array_key_exists($iso2, $sortOrderLookup) ? ($sortOrderLookup[$iso2] + 1) : 999,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('registration_phone_countries')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_phone_countries');
    }
};

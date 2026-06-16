<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 80);
            $table->string('symbol', 12)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->decimal('rounding_step', 18, 4)->default(0.01);
            $table->string('rounding_mode', 20)->default('nearest');
            $table->boolean('is_base')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->string('locale_hint', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_base'], 'currencies_active_base_idx');
        });

        DB::table('currencies')->insert([
            ['code' => 'IQD', 'name' => 'Iraqi Dinar', 'symbol' => 'IQD', 'decimal_places' => 0, 'rounding_step' => 250, 'rounding_mode' => 'nearest', 'is_base' => true, 'is_active' => true, 'locale_hint' => 'ar_IQ', 'meta' => json_encode(['region' => 'iq']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_US', 'meta' => json_encode(['region' => 'us']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'de_DE', 'meta' => json_encode(['region' => 'eu']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_CA', 'meta' => json_encode(['region' => 'ca']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_AU', 'meta' => json_encode(['region' => 'au']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'en_GB', 'meta' => json_encode(['region' => 'gb']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimal_places' => 2, 'rounding_step' => 0.25, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'de_CH', 'meta' => json_encode(['region' => 'ch']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimal_places' => 2, 'rounding_step' => 1, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'tr_TR', 'meta' => json_encode(['region' => 'tr']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'SEK', 'decimal_places' => 2, 'rounding_step' => 1, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'sv_SE', 'meta' => json_encode(['region' => 'se']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'DKK', 'decimal_places' => 2, 'rounding_step' => 1, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'da_DK', 'meta' => json_encode(['region' => 'dk']), 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'IRR', 'name' => 'Iranian Rial', 'symbol' => 'IRR', 'decimal_places' => 0, 'rounding_step' => 1000, 'rounding_mode' => 'nearest', 'is_base' => false, 'is_active' => true, 'locale_hint' => 'fa_IR', 'meta' => json_encode(['region' => 'ir']), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};

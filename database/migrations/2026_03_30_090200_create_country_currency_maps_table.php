<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_currency_maps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('country_code', 2)->unique();
            $table->char('currency_code', 3)->index();
            $table->string('source', 40)->default('system')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::table('country_currency_maps')->insert([
            ['country_code' => 'IQ', 'currency_code' => 'IQD', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'primary market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'DE', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'FR', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'AT', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'BE', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'IT', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'NL', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'ES', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'eurozone']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'AX', 'currency_code' => 'EUR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'supported region']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'US', 'currency_code' => 'USD', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'base market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'CA', 'currency_code' => 'CAD', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'AU', 'currency_code' => 'AUD', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'GB', 'currency_code' => 'GBP', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'CH', 'currency_code' => 'CHF', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'TR', 'currency_code' => 'TRY', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'SE', 'currency_code' => 'SEK', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'DK', 'currency_code' => 'DKK', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'IR', 'currency_code' => 'IRR', 'source' => 'system', 'is_active' => true, 'meta' => json_encode(['reason' => 'local market']), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('country_currency_maps');
    }
};

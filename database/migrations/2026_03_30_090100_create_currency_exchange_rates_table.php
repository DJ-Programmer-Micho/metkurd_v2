<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('currency_exchange_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('base_currency_code', 3);
            $table->char('quote_currency_code', 3);
            $table->decimal('rate', 18, 8);
            $table->string('source', 80)->nullable()->index();
            $table->timestamp('effective_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('is_current')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('base_currency_code')->references('code')->on('currencies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('quote_currency_code')->references('code')->on('currencies')->cascadeOnUpdate()->restrictOnDelete();

            $table->unique(
                ['base_currency_code', 'quote_currency_code', 'effective_at'],
                'currency_rates_pair_effective_unique'
            );
            $table->index(
                ['base_currency_code', 'quote_currency_code', 'is_current'],
                'currency_rates_pair_current_idx'
            );
        });

        DB::table('currency_exchange_rates')->insert([
            'base_currency_code' => 'IQD',
            'quote_currency_code' => 'IQD',
            'rate' => 1,
            'source' => 'system',
            'effective_at' => now(),
            'expires_at' => null,
            'is_current' => true,
            'meta' => json_encode(['note' => 'Base billing currency self-rate']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchange_rates');
    }
};

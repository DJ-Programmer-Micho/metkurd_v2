<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_products', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 50)->unique(); // addon_10k, addon_50k, addon_100k
            $table->string('name', 120);

            $table->unsignedBigInteger('credits_amount');
            $table->decimal('price_usd', 10, 2);
            $table->decimal('price_iqd', 14, 0)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_products');
    }
};

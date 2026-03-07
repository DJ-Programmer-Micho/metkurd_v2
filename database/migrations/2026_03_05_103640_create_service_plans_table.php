<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_plans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 50)->unique();            // free, creator, pro, premium
            $table->string('name', 120);
            $table->string('billing_interval', 20)->default('monthly')->index(); // monthly, yearly, lifetime

            $table->unsignedBigInteger('monthly_credits')->default(0); // Free => 10000
            $table->boolean('is_free')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->decimal('price_usd_monthly', 10, 2)->nullable();
            $table->decimal('price_usd_yearly', 10, 2)->nullable();

            $table->json('ui_features')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_plans');
    }
};

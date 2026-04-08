<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique();
            $table->string('driver', 50)->index();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('icon', 120)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_visible')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->json('supported_currencies')->nullable();
            $table->json('supported_purchase_types')->nullable();
            $table->boolean('supports_recurring')->default(false);
            $table->boolean('supports_refunds')->default(false);
            $table->boolean('supports_webhooks')->default(false);
            $table->boolean('supports_redirect')->default(false);
            $table->boolean('supports_qr')->default(false);
            $table->json('settings')->nullable();
            $table->json('fee_config')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_visible', 'sort_order'], 'pm_active_visible_sort_idx');
            $table->index(['driver', 'is_active'], 'pm_driver_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};

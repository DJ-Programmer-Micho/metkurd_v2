<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('is_stackable')->default(false);
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 4);
            $table->string('target_type', 40)->default('all')->index();
            $table->json('applies_to_codes')->nullable();
            $table->json('applies_to_billing_cycles')->nullable();
            $table->boolean('first_time_subscribers_only')->default(false)->index();
            $table->string('duration_type', 40)->default('once');
            $table->unsignedSmallInteger('duration_cycles')->nullable();
            $table->unsignedInteger('max_total_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->decimal('minimum_amount_iqd', 14, 0)->nullable();
            $table->char('currency', 3)->default('IQD');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'target_type'], 'coupons_active_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};

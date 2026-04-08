<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_payment_methods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('provider', 40)->index();
            $table->string('provider_customer_ref', 190)->nullable()->index();
            $table->string('provider_method_ref', 190)->nullable()->index();
            $table->string('payment_method_type', 40)->index();
            $table->string('brand', 80)->nullable();
            $table->string('masked_pan', 40)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('card_origin', 20)->nullable()->index();
            $table->timestamp('token_expires_at')->nullable()->index();
            $table->boolean('reusable_for_recurring')->default(false)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->unique(['provider', 'provider_method_ref'], 'cpm_provider_method_unique');
            $table->index(['customer_id', 'provider', 'is_active'], 'cpm_customer_provider_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_methods');
    }
};

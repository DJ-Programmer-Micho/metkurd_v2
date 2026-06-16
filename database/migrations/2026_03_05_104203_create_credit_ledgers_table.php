<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledgers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();

            $table->string('type', 40)->index();   // monthly_grant, addon_purchase, spend, refund, adjustment, expiry, rollover
            $table->string('bucket', 20)->default('combined')->index(); // subscription, addon, combined

            // signed value: +10000 / -250 / +500
            $table->bigInteger('credits_delta');

            // cached balances after the row was applied
            $table->unsignedBigInteger('balance_after')->nullable();
            $table->unsignedBigInteger('subscription_balance_after')->nullable();
            $table->unsignedBigInteger('addon_balance_after')->nullable();

            // relation / audit
            $table->string('related_type', 150)->nullable();
            $table->string('related_id', 150)->nullable();
            $table->string('reference_code', 120)->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->index(['customer_id', 'created_at'], 'cl_customer_created_idx');
            $table->index(['related_type', 'related_id'], 'cl_related_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledgers');
    }
};

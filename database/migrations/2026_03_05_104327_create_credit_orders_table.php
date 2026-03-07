<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();

            $table->string('order_type', 30)->index(); // subscription, addon, adjustment
            $table->string('status', 30)->default('pending')->index(); // pending, paid, failed, refunded, canceled

            // source object
            $table->string('source_type', 60)->nullable()->index(); // service_plan, credit_product
            $table->unsignedBigInteger('service_plan_id')->nullable()->index();
            $table->unsignedBigInteger('credit_product_id')->nullable()->index();

            $table->unsignedBigInteger('credits_amount')->default(0);
            $table->decimal('amount_usd', 12, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            $table->string('provider', 60)->nullable()->index();
            $table->string('provider_ref', 190)->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('service_plan_id')->references('id')->on('service_plans')->nullOnDelete();
            $table->foreign('credit_product_id')->references('id')->on('credit_products')->nullOnDelete();

            $table->index(['customer_id', 'status', 'created_at'], 'co_customer_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_orders');
    }
};

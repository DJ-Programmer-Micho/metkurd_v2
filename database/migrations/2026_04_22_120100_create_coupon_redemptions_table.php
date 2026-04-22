<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('purchase_type', 40)->index();
            $table->string('purchasable_type')->nullable();
            $table->unsignedBigInteger('purchasable_id')->nullable();
            $table->string('item_code', 120)->nullable()->index();
            $table->string('billing_cycle', 40)->nullable()->index();
            $table->unsignedInteger('cycle_index')->nullable();
            $table->string('redemption_type', 20)->default('checkout')->index();
            $table->string('status', 20)->default('reserved')->index();
            $table->string('coupon_code', 80);
            $table->decimal('original_amount_iqd', 14, 0);
            $table->decimal('discount_amount_iqd', 14, 0)->default(0);
            $table->decimal('final_amount_iqd', 14, 0);
            $table->json('discount_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['coupon_id', 'customer_id', 'redemption_type', 'status'],
                'coupon_redemptions_coupon_customer_type_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};

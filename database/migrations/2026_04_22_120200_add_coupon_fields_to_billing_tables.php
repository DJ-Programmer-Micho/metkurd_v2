<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('customer_id')->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code', 80)->nullable()->after('coupon_id')->index();
            $table->decimal('original_amount_iqd', 14, 0)->nullable()->after('currency');
            $table->decimal('discount_amount_iqd', 14, 0)->default(0)->after('original_amount_iqd');
            $table->decimal('discounted_amount_iqd', 14, 0)->nullable()->after('discount_amount_iqd');
        });

        Schema::table('credit_orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('customer_id')->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code', 80)->nullable()->after('coupon_id')->index();
            $table->decimal('original_amount_iqd', 14, 0)->nullable()->after('base_amount_iqd');
            $table->decimal('discount_amount_iqd', 14, 0)->default(0)->after('original_amount_iqd');
            $table->decimal('discounted_amount_iqd', 14, 0)->nullable()->after('discount_amount_iqd');
        });

        Schema::table('customer_service_subscriptions', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('payment_id')->constrained('coupons')->nullOnDelete();
            $table->decimal('original_price_iqd_snapshot', 14, 0)->nullable()->after('price_iqd_snapshot');
            $table->unsignedInteger('discount_cycles_consumed')->default(0)->after('original_price_iqd_snapshot');
        });

        Schema::table('customer_storage_subscriptions', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('payment_id')->constrained('coupons')->nullOnDelete();
            $table->decimal('original_price_iqd_snapshot', 14, 0)->nullable()->after('price_iqd_snapshot');
            $table->unsignedInteger('discount_cycles_consumed')->default(0)->after('original_price_iqd_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('customer_storage_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['original_price_iqd_snapshot', 'discount_cycles_consumed']);
        });

        Schema::table('customer_service_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['original_price_iqd_snapshot', 'discount_cycles_consumed']);
        });

        Schema::table('credit_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'original_amount_iqd', 'discount_amount_iqd', 'discounted_amount_iqd']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'original_amount_iqd', 'discount_amount_iqd', 'discounted_amount_iqd']);
        });
    }
};

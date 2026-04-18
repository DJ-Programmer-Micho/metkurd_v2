<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->after('payment_intent_id')->index();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });

        Schema::table('customer_service_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->after('customer_id')->index();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });

        Schema::table('customer_storage_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->after('customer_id')->index();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_storage_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::table('customer_service_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::table('credit_orders', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
    }
};

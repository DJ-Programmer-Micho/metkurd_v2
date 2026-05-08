<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_conversion_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_name', 60)->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('transaction_id', 120)->nullable()->index();
            $table->string('dedupe_key', 190)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->timestamp('fired_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();

            $table->unique(['event_name', 'payment_id'], 'ad_conv_event_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_conversion_events');
    }
};

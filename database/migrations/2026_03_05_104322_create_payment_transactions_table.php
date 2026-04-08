<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('payment_intent_id')->index();
            $table->unsignedBigInteger('parent_transaction_id')->nullable()->index();
            $table->string('provider', 40)->index();
            $table->string('transaction_type', 40)->index();
            $table->string('status', 30)->default('initiated')->index();
            $table->string('status_reason', 190)->nullable();
            $table->string('merchant_transaction_id', 120)->nullable()->index();
            $table->string('provider_transaction_id', 190)->nullable()->index();
            $table->string('provider_payment_id', 190)->nullable()->index();
            $table->string('provider_purchase_id', 190)->nullable()->index();
            $table->string('provider_reference', 190)->nullable()->index();
            $table->decimal('amount_iqd', 14, 0)->nullable();
            $table->decimal('gross_amount_iqd', 14, 0)->nullable();
            $table->decimal('surcharge_amount_iqd', 14, 0)->nullable();
            $table->decimal('provider_fee_amount_iqd', 14, 0)->nullable();
            $table->decimal('net_amount_iqd', 14, 0)->nullable();
            $table->char('currency', 3)->default('IQD');
            $table->string('card_origin', 20)->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->cascadeOnDelete();
            $table->foreign('parent_transaction_id')->references('id')->on('payment_transactions')->nullOnDelete();
            $table->index(['payment_intent_id', 'transaction_type', 'created_at'], 'pt_intent_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};

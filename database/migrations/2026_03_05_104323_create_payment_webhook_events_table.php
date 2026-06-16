<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider', 40)->index();
            $table->string('event_key', 190)->unique();
            $table->unsignedBigInteger('payment_intent_id')->nullable()->index();
            $table->string('merchant_transaction_id', 120)->nullable()->index();
            $table->string('provider_payment_id', 190)->nullable()->index();
            $table->string('provider_transaction_id', 190)->nullable()->index();
            $table->string('provider_purchase_id', 190)->nullable()->index();
            $table->string('event_type', 80)->nullable()->index();
            $table->string('event_status', 80)->nullable()->index();
            $table->boolean('signature_valid')->nullable()->index();
            $table->string('processing_status', 30)->default('received')->index();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();
            $table->index(['provider', 'processing_status', 'created_at'], 'pwe_provider_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

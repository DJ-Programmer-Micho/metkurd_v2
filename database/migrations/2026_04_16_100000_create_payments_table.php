<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('provider', 40)->index();
            $table->string('purchase_type', 40)->index();
            $table->string('payment_mode', 30)->index();
            $table->string('status', 30)->default('pending')->index();
            $table->string('local_reference', 120)->unique();
            $table->string('idempotency_key', 120)->unique();
            $table->string('fib_payment_id', 190)->nullable()->unique();
            $table->string('readable_code', 40)->nullable()->index();
            $table->longText('qr_code')->nullable();
            $table->json('provider_links')->nullable();
            $table->decimal('amount', 14, 0);
            $table->char('currency', 3)->default('IQD');
            $table->string('status_reason', 190)->nullable();
            $table->string('declining_reason', 120)->nullable()->index();
            $table->string('provider_status', 80)->nullable()->index();
            $table->json('callback_payload')->nullable();
            $table->json('create_payload')->nullable();
            $table->json('create_response')->nullable();
            $table->json('status_response')->nullable();
            $table->json('cancel_response')->nullable();
            $table->json('purchase_snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->nullableMorphs('purchasable');
            $table->timestamp('valid_until')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('canceled_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamp('fulfilled_at')->nullable()->index();
            $table->timestamp('last_status_checked_at')->nullable()->index();
            $table->timestamp('last_callback_received_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->index(['customer_id', 'status', 'created_at'], 'payments_customer_status_created_idx');
            $table->index(['purchase_type', 'payment_mode', 'status'], 'payments_type_mode_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('customer_payment_method_id')->nullable()->index();
            $table->string('provider', 40)->index();
            $table->string('payment_method', 40)->nullable()->index();
            $table->string('purpose_type', 40)->index();
            $table->unsignedBigInteger('purpose_id')->nullable()->index();
            $table->string('purpose_code', 80)->nullable()->index();
            $table->string('purpose_name', 120)->nullable();
            $table->string('billing_interval', 20)->nullable()->index();
            $table->boolean('is_recurring')->default(false)->index();
            $table->string('recurring_strategy', 30)->default('none')->index();

            $table->char('base_currency_code', 3)->default('IQD')->index();
            $table->decimal('base_amount_iqd', 14, 0);
            $table->decimal('gross_amount_iqd', 14, 0);
            $table->decimal('surcharge_amount_iqd', 14, 0)->default(0);
            $table->decimal('provider_fee_amount_iqd', 14, 0)->default(0);
            $table->decimal('net_amount_iqd', 14, 0)->nullable();
            $table->char('fee_currency_code', 3)->default('IQD');

            $table->char('display_currency_code', 3)->nullable()->index();
            $table->decimal('display_exchange_rate', 18, 8)->nullable();
            $table->decimal('display_amount_raw', 18, 8)->nullable();
            $table->decimal('display_amount_rounded', 18, 4)->nullable();
            $table->decimal('display_rounding_step', 18, 4)->nullable();
            $table->string('display_rounding_mode', 20)->nullable();
            $table->char('display_country_code', 2)->nullable()->index();

            $table->string('status', 30)->default('pending')->index();
            $table->string('status_reason', 190)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('merchant_transaction_id', 120)->unique();
            $table->string('provider_payment_id', 190)->nullable()->index();
            $table->string('provider_transaction_id', 190)->nullable()->index();
            $table->string('provider_purchase_id', 190)->nullable()->index();
            $table->string('provider_customer_ref', 190)->nullable()->index();
            $table->string('provider_schedule_ref', 190)->nullable()->index();
            $table->string('card_origin', 20)->nullable()->index();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('authorized_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('canceled_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamp('refunded_at')->nullable()->index();
            $table->timestamp('fulfilled_at')->nullable()->index();
            $table->timestamp('last_status_synced_at')->nullable()->index();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('customer_payment_method_id')->references('id')->on('customer_payment_methods')->nullOnDelete();
            $table->index(['customer_id', 'status', 'created_at'], 'pi_customer_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};

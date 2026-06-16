<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_service_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('service_plan_id')->index();
            $table->unsignedBigInteger('previous_service_plan_id')->nullable()->index();

            $table->string('status', 20)->default('active')->index(); // trialing, active, canceled, expired, paused, ended
            $table->string('source', 40)->nullable()->index(); // manual, areeba, zaincash, fib, fake
            $table->string('provider_ref', 190)->nullable()->index();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('upgraded_at')->nullable();

            $table->date('cycle_started_on')->nullable()->index();
            $table->date('cycle_ends_on')->nullable()->index();
            $table->date('next_renewal_on')->nullable()->index();

            $table->boolean('auto_renew')->default(true)->index();
            $table->unsignedBigInteger('customer_payment_method_id')->nullable()->index();
            $table->string('renewal_strategy', 30)->default('manual_renewal')->index();
            $table->decimal('price_iqd_snapshot', 14, 0)->nullable();
            $table->char('display_currency_code', 3)->nullable()->index();
            $table->decimal('display_exchange_rate', 18, 8)->nullable();
            $table->decimal('display_amount_raw', 18, 8)->nullable();
            $table->decimal('display_amount_rounded', 18, 4)->nullable();
            $table->decimal('display_rounding_step', 18, 4)->nullable();
            $table->string('display_rounding_mode', 20)->nullable();
            $table->char('display_country_code', 2)->nullable()->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('service_plan_id')->references('id')->on('service_plans')->restrictOnDelete();
            $table->foreign('previous_service_plan_id')->references('id')->on('service_plans')->nullOnDelete();
            $table->foreign('customer_payment_method_id', 'cssvc_pm_method_fk')
                ->references('id')
                ->on('customer_payment_methods')
                ->nullOnDelete();

            $table->index(['customer_id', 'status', 'cycle_ends_on'], 'css_customer_status_cycle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_subscriptions');
    }
};

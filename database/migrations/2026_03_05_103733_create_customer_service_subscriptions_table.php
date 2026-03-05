<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('service_plan_id')->index();

            $table->string('status', 20)->default('active')->index(); // active, canceled, expired, paused

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // billing cycle anchor
            $table->date('cycle_started_on')->nullable()->index();
            $table->date('cycle_ends_on')->nullable()->index();

            // upgrade tracking
            $table->unsignedBigInteger('previous_service_plan_id')->nullable();
            $table->timestamp('upgraded_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('service_plan_id')->references('id')->on('service_plans');
            $table->foreign('previous_service_plan_id')->references('id')->on('service_plans');

            $table->index(['customer_id','status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_subscriptions');
    }
};
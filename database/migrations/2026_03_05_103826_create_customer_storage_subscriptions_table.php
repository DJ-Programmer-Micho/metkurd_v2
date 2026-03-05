<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_storage_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('storage_plan_id')->index();

            $table->string('status', 20)->default('active')->index(); // active, canceled, expired, paused

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('storage_plan_id')->references('id')->on('storage_plans');

            $table->index(['customer_id','status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_storage_subscriptions');
    }
};
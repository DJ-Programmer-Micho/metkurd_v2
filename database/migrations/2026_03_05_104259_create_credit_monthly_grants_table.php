<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_monthly_grants', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('service_plan_id')->index();

            // e.g. "2026-03"
            $table->char('year_month', 7)->index();

            $table->unsignedInteger('granted_credits');

            $table->timestamp('granted_at')->useCurrent();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('service_plan_id')->references('id')->on('service_plans');

            $table->unique(['customer_id','year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_monthly_grants');
    }
};
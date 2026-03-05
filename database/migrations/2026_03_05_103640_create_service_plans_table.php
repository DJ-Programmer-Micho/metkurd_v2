<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_plans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 40)->unique(); // free, student, pro, premium
            $table->string('name', 80);

            $table->unsignedInteger('monthly_credits')->default(0); // Free=50, Student=2000, etc.

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Optional: UI flags only (not primary gating logic)
            $table->json('ui_features')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_plans');
    }
};
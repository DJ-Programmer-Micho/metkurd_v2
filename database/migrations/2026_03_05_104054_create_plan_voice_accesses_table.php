<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_voice_access', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('service_plan_id')->index();
            $table->unsignedBigInteger('voice_id')->index();

            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('service_plan_id')->references('id')->on('service_plans')->cascadeOnDelete();
            $table->foreign('voice_id')->references('id')->on('voices')->cascadeOnDelete();

            $table->unique(['service_plan_id','voice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_voice_access');
    }
};
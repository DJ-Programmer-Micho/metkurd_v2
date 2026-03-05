<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('service_plan_id')->index();
            $table->unsignedBigInteger('tool_action_id')->index();

            $table->boolean('allowed')->default(true);

            // optional limits: max_duration_sec, max_files_day, etc.
            $table->json('limits')->nullable();

            $table->timestamps();

            $table->foreign('service_plan_id')->references('id')->on('service_plans')->cascadeOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->cascadeOnDelete();

            $table->unique(['service_plan_id','tool_action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};
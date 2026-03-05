<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('tool_action_id')->index();

            // fixed | free | conditional | tiered
            $table->string('rule_type', 30)->index();

            // higher wins
            $table->unsignedSmallInteger('priority')->default(100)->index();

            // match conditions (plan_codes, etc.)
            $table->json('conditions')->nullable();

            // credits cost when applicable (fixed/conditional result)
            $table->unsignedInteger('cost_credits')->nullable();

            // optional formula/tier config
            $table->json('config')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->cascadeOnDelete();

            $table->index(['tool_action_id','is_active','priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_pricing_rules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('tool_action_id')->index();

            $table->string('rule_type', 30)->index(); // fixed/free/conditional
            $table->unsignedSmallInteger('priority')->default(1)->index(); // customer overrides should win

            $table->json('conditions')->nullable();
            $table->unsignedInteger('cost_credits')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->cascadeOnDelete();

            // ✅ short index name to avoid MySQL 64-char limit
            $table->index(
                ['customer_id','tool_action_id','is_active','priority'],
                'cpr_cust_action_active_pri_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pricing_rules');
    }
};
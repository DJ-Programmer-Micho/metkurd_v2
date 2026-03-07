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

            $table->string('rule_type', 20)->default('unit')->index();
            $table->unsignedSmallInteger('priority')->default(1000)->index(); // customer override should win

            $table->string('metric_code', 50)->index();
            $table->decimal('unit_size', 12, 4)->default(1.0000);
            $table->decimal('credits_per_unit', 12, 4)->nullable();
            $table->string('rounding_mode', 20)->default('ceil')->index();
            $table->decimal('rounding_step', 12, 4)->default(1.0000);
            $table->unsignedBigInteger('minimum_credits')->default(0);

            $table->json('conditions')->nullable();
            $table->json('config')->nullable();

            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->cascadeOnDelete();

            $table->index(['customer_id', 'tool_action_id', 'is_active', 'priority'], 'cpr_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pricing_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('tool_action_id')->index();
            $table->unsignedBigInteger('service_plan_id')->nullable()->index(); // nullable => global default rule

            $table->string('rule_scope', 20)->default('global')->index();       // global, plan
            $table->string('rule_type', 20)->default('unit')->index();          // free, fixed, unit, matrix
            $table->unsignedSmallInteger('priority')->default(100)->index();    // higher wins

            // what is being metered
            $table->string('metric_code', 50)->index();                         // character, minute, page, stem_output
            $table->decimal('unit_size', 12, 4)->default(1.0000);               // 1 char, 60 sec, 1 page, 1 output stem
            $table->decimal('credits_per_unit', 12, 4)->nullable();             // e.g. 1.2, 1000, 500

            // rounding behaviour
            $table->string('rounding_mode', 20)->default('ceil')->index();      // none, ceil, floor, nearest
            $table->decimal('rounding_step', 12, 4)->default(1.0000);           // ceil to whole credit by default
            $table->unsignedBigInteger('minimum_credits')->default(0);

            // conditions/examples:
            // {"emotion":"happy"}
            // {"quality":"wav"}
            // {"resolution":"1080p"}
            // {"separation_count":4}
            $table->json('conditions')->nullable();

            // optional extra config for matrix/tiered pricing
            $table->json('config')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();

            $table->timestamps();

            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->cascadeOnDelete();
            $table->foreign('service_plan_id')->references('id')->on('service_plans')->cascadeOnDelete();

            $table->index(['tool_action_id', 'service_plan_id', 'is_active', 'priority'], 'pr_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};

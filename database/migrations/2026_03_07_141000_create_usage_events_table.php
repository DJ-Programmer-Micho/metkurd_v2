<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('tool_action_id')->index();

            // optional link to actual job / render / upload / OCR file / etc.
            $table->string('source_type', 150)->nullable();
            $table->string('source_id', 150)->nullable();

            $table->string('status', 20)->default('charged')->index(); // quoted, charged, refunded, void

            $table->string('metric_code', 50)->index(); // character, minute, page, stem_output
            $table->decimal('input_quantity', 14, 4)->default(0);      // e.g. chars, seconds, pages, outputs
            $table->decimal('billable_quantity', 14, 4)->default(0);   // after normalization/rounding basis
            $table->decimal('unit_size', 12, 4)->default(1.0000);
            $table->decimal('unit_price_credits', 12, 4)->default(0);

            $table->string('rounding_mode', 20)->default('ceil');
            $table->decimal('rounding_step', 12, 4)->default(1.0000);
            $table->unsignedBigInteger('total_credits')->default(0);

            $table->unsignedBigInteger('pricing_rule_id')->nullable();
            $table->unsignedBigInteger('customer_pricing_rule_id')->nullable();

            $table->json('breakdown')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('charged_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->restrictOnDelete();
            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->nullOnDelete();
            $table->foreign('customer_pricing_rule_id')->references('id')->on('customer_pricing_rules')->nullOnDelete();

            $table->index(['customer_id', 'tool_action_id', 'charged_at'], 'ue_customer_action_charged_idx');
            $table->index(['source_type', 'source_id'], 'ue_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};

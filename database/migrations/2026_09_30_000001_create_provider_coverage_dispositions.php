<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_coverage_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Historical identities deliberately survive retirement of processing rows.
            $table->unsignedBigInteger('original_payment_id')->unique();
            $table->string('provider', 30);
            $table->string('provider_subscription_id');
            $table->string('subscription_kind', 10);
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('plan_id');
            $table->unique(['subscription_kind', 'subscription_id'], 'provider_coverage_subscription_unique');
            // Offset-bearing ISO strings preserve provider milliseconds on all supported engines.
            $table->string('coverage_start', 40);
            $table->string('coverage_end', 40);
            $table->boolean('renewal_stop_confirmed');
            $table->unsignedBigInteger('evidence_event_id');
            $table->string('status', 20);
            $table->string('provenance', 50);
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->foreign('operation_id')->references('id')->on('admin_operations')->restrictOnDelete();
            $table->text('reason');
            $table->string('review_reference', 200);
            $table->string('source_hash', 64);
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('provider_coverage_dispositions')->exists()) {
            throw new RuntimeException('Retained provider coverage requires an explicit preservation review; rollback refused.');
        }
        Schema::dropIfExists('provider_coverage_dispositions');
    }
};

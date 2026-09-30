<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_obligation_reviews', function (Blueprint $table) {
            $table->id();
            // Logical historical IDs survive processing retirement; never cascade evidence away.
            $table->unsignedBigInteger('original_payment_id')->index();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_object_id');
            $table->string('kind', 30);
            $table->string('outcome', 40);
            $table->string('provider_status', 30)->nullable();
            $table->unsignedBigInteger('evidence_event_id');
            $table->string('basis_hash', 64);
            $table->string('manifest_hash', 64);
            $table->json('evidence');
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('admin_operations')->restrictOnDelete();
            $table->unique(['operation_id', 'original_payment_id'], 'provider_review_operation_payment_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('provider_obligation_reviews')->exists()) {
            throw new RuntimeException('Provider review history must be retained; populated rollback refused.');
        }
        Schema::dropIfExists('provider_obligation_reviews');
    }
};

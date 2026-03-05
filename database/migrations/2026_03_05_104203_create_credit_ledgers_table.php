<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();

            // monthly_grant | purchase | spend | refund | adjustment
            $table->string('type', 30)->index();

            // + or - (store signed)
            $table->integer('credits_delta');

            // for audit (optional cached)
            $table->integer('balance_after')->nullable();

            // what caused it (ml_jobs, credit_orders, etc.)
            $table->string('related_type', 120)->nullable();
            $table->string('related_id', 120)->nullable();

            $table->json('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->index(['customer_id','created_at']);
            $table->index(['related_type','related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
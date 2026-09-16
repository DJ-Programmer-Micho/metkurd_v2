<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_plan_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->unique()->constrained('customer_service_subscriptions')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->foreign('operation_id')->references('id')->on('admin_operations')->restrictOnDelete();
            $table->string('reference', 190);
            $table->string('status', 24)->default('scheduled');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedBigInteger('agreed_amount_iqd')->nullable();
            $table->unsignedBigInteger('app_monthly_credits');
            $table->unsignedBigInteger('api_monthly_credits');
            $table->text('reason');
            $table->string('review_code', 32)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'starts_at', 'ends_at'], 'service_agreement_dates_idx');
            $table->index(['status', 'starts_at'], 'service_agreement_due_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('service_plan_agreements')->exists()) {
            throw new LogicException('Retain service agreement history; a populated table cannot be rolled back.');
        }
        Schema::dropIfExists('service_plan_agreements');
    }
};

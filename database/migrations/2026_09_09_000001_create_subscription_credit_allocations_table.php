<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('customer_service_subscriptions')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('cycle_key', 190);
            $table->string('allocation_type', 32);
            $table->dateTime('cycle_started_at');
            $table->dateTime('paid_through')->nullable();
            $table->string('status', 16)->default('pending');
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'cycle_key'], 'subscription_allocation_cycle_uq');
            $table->unique(['payment_id', 'cycle_key'], 'payment_allocation_cycle_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('subscription_credit_allocations')->exists()) {
            throw new LogicException('Retain subscription allocation history; a populated allocation table cannot be rolled back.');
        }
        Schema::dropIfExists('subscription_credit_allocations');
    }
};

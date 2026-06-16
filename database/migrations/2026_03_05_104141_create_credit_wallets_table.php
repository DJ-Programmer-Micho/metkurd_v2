<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->unique();

            // Main cached balances
            $table->unsignedBigInteger('balance_credits')->default(0);                // total spendable now
            $table->unsignedBigInteger('subscription_balance_credits')->default(0);   // monthly/subscription bucket
            $table->unsignedBigInteger('addon_balance_credits')->default(0);          // purchased add-on bucket

            // Lifetime audit counters
            $table->unsignedBigInteger('lifetime_earned')->default(0);
            $table->unsignedBigInteger('lifetime_spent')->default(0);
            $table->unsignedBigInteger('lifetime_refunded')->default(0);

            // Current subscription cycle snapshot
            $table->date('cycle_started_on')->nullable()->index();
            $table->date('cycle_ends_on')->nullable()->index();
            $table->char('current_cycle_key', 7)->nullable()->index(); // 2026-03

            $table->timestamp('last_granted_at')->nullable()->index();
            $table->timestamp('last_charged_at')->nullable()->index();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_wallets');
    }
};

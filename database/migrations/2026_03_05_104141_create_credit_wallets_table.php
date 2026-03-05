<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->unique();

            $table->integer('balance_credits')->default(0); // cached balance
            $table->integer('lifetime_earned')->default(0);
            $table->integer('lifetime_spent')->default(0);

            // current cycle tracking
            $table->date('cycle_started_on')->nullable()->index();
            $table->date('cycle_ends_on')->nullable()->index();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_wallets');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();

            $table->string('status', 30)->default('pending')->index(); // pending, paid, failed, refunded
            $table->unsignedInteger('credits_amount');
            $table->decimal('amount_usd', 10, 4)->nullable();

            $table->string('provider', 60)->nullable()->index(); // areeba, fib, zaincash etc.
            $table->string('provider_ref', 190)->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_orders');
    }
};
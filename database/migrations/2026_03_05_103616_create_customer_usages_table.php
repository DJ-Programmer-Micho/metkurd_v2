<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_usages', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->unique();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->unsignedBigInteger('storage_used_bytes')->default(0);

            // Optional: fast dashboards
            $table->unsignedBigInteger('jobs_total')->default(0);
            $table->unsignedBigInteger('jobs_succeeded')->default(0);
            $table->unsignedBigInteger('jobs_failed')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_usages');
    }
};
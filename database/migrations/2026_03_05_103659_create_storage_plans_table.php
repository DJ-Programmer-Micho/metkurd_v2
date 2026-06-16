<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_plans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 40)->unique(); // free-512, student-3072, pro-5120, premium-10240
            $table->string('name', 80);

            $table->unsignedInteger('quota_mb')->default(512);
            $table->decimal('price_usd', 10, 2)->default(0);
            $table->decimal('price_iqd', 14, 0)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_plans');
    }
};

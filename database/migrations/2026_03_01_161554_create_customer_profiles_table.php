<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->unique();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('brand_name', 190)->nullable();

            $table->string('country', 2)->nullable()->index();
            $table->char('display_currency_code', 3)->nullable()->index();
            $table->string('city', 120)->nullable()->index();
            $table->string('address', 190)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('phone_number', 30)->nullable();

            $table->string('avatar', 512)->nullable();

            $table->timestamps();

            $table->index(['country', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};

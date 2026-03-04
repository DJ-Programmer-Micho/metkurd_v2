<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('job_title')->nullable();     // student, engineer, etc.
            $table->string('brand_name')->nullable();
            $table->string('country', 2)->nullable();    // ISO code if you prefer
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('phone_number', 30)->nullable();
            $table->string('avatar')->nullable();

            $table->timestamps();

            $table->index(['city', 'country']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};

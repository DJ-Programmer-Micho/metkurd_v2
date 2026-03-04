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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');

            $table->tinyInteger('status')->default(1);

            $table->boolean('email_verify')->default(false);
            $table->boolean('phone_verify')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('g_id')->nullable()->unique();
            $table->string('h_id')->nullable()->unique();
            $table->string('uid')->nullable()->unique();

            $table->char('email_otp_number', 6)->nullable();
            $table->char('phone_otp_number', 6)->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

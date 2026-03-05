<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('username', 50)->unique();
            $table->string('email', 190)->unique();
            $table->string('password');

            $table->unsignedTinyInteger('status')->default(1)->index(); // 1 active, 0 blocked, etc.

            $table->boolean('email_verify')->default(false);
            $table->boolean('phone_verify')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('g_id', 190)->nullable()->unique();
            $table->string('h_id', 190)->nullable()->unique();
            $table->string('uid', 190)->nullable()->unique();

            $table->char('email_otp_number', 6)->nullable();
            $table->char('phone_otp_number', 6)->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
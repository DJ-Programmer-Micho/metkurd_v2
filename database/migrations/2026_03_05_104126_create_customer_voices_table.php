<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_voices', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();

            // builtin voice optional link
            $table->unsignedBigInteger('voice_id')->nullable()->index();

            // builtin | cloned
            $table->string('type', 20)->default('cloned')->index();

            $table->string('name', 160)->nullable(); // user-friendly name
            $table->string('provider', 60)->nullable()->index(); // xtts, etc.

            // where cloned voice embedding/model files are stored
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 512)->nullable();

            $table->json('meta')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('voice_id')->references('id')->on('voices')->nullOnDelete();

            $table->index(['customer_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_voices');
    }
};

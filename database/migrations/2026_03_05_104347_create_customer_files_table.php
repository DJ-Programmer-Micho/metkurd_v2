<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_files', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();

            $table->string('purpose', 40)->index(); // input, output, asset, voice_ref, temp, etc.
            $table->string('tool_code', 60)->nullable()->index();

            $table->string('disk', 40)->default('public')->index();
            $table->string('path', 700); // S3 key or local path
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->string('mime', 120)->nullable();
            $table->string('checksum', 128)->nullable()->index();

            $table->string('status', 20)->default('active')->index(); // active, deleted
            $table->timestamp('deleted_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_files');
    }
};

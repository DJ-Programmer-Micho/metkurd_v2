<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voices', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 80)->unique(); // liza, taha_fathi, etc.
            $table->string('name', 160);

            // which tool/model uses it (usually xtts)
            $table->string('tool_code', 60)->default('xtts')->index();
            $table->string('model_code', 120)->nullable()->index();

            $table->boolean('is_public')->default(true)->index(); // built-in public voice
            $table->boolean('is_active')->default(true)->index();

            $table->json('meta')->nullable(); // gender, language, tags, etc.

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voices');
    }
};
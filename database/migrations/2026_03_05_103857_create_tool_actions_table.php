<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tool_actions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('tool_id')->index();
            $table->string('code', 80); // generate, transcribe, download_audio, download_video
            $table->string('full_code', 160)->unique(); // xtts.generate, yt_downloader.download_video

            $table->string('name', 160);
            $table->boolean('is_active')->default(true)->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('tool_id')->references('id')->on('tools')->cascadeOnDelete();

            $table->index(['tool_id','code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_actions');
    }
};
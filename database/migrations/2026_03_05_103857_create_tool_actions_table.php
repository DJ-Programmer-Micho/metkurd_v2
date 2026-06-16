<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_actions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('tool_code', 60)->index();       // tts, clone_tts, asr, stem, ocr, youtube_audio, youtube_video
            $table->string('action_code', 60)->index();     // standard, happy, mp3, wav, p480, p720, p1080, p4k, sep2, sep4
            $table->string('full_code', 130)->unique();     // tts.standard, clone_tts.happy, youtube_video.p720
            $table->string('name', 160);

            $table->string('default_metric_code', 50)->nullable()->index(); // character, minute, page, stem_output
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['tool_code', 'action_code'], 'ta_tool_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_actions');
    }
};

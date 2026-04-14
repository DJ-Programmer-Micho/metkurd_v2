<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return;
        }

        Schema::table('landing_tool_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('landing_tool_pages', 'square_image_path')) {
                $table->string('square_image_path', 255)->nullable()->after('icon_class');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return;
        }

        Schema::table('landing_tool_pages', function (Blueprint $table) {
            if (Schema::hasColumn('landing_tool_pages', 'square_image_path')) {
                $table->dropColumn('square_image_path');
            }
        });
    }
};

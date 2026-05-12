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
            if (! Schema::hasColumn('landing_tool_pages', 'demo_type')) {
                $table->string('demo_type', 80)->nullable()->after('card_image_path')->index();
            }

            if (! Schema::hasColumn('landing_tool_pages', 'demo_config')) {
                $table->json('demo_config')->nullable()->after('demo_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return;
        }

        Schema::table('landing_tool_pages', function (Blueprint $table) {
            if (Schema::hasColumn('landing_tool_pages', 'demo_config')) {
                $table->dropColumn('demo_config');
            }

            if (Schema::hasColumn('landing_tool_pages', 'demo_type')) {
                $table->dropColumn('demo_type');
            }
        });
    }
};


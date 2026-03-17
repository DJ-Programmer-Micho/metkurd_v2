<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_plans', function (Blueprint $table) {
            $table->decimal('price_usd', 10, 2)->default(0)->after('quota_mb');
        });
    }

    public function down(): void
    {
        Schema::table('storage_plans', function (Blueprint $table) {
            $table->dropColumn('price_usd');
        });
    }
};

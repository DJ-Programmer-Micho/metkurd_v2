<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('telegram_unverified_register_sent_at')->nullable()->after('phone_verified_at')->index();
            $table->timestamp('telegram_verified_register_sent_at')->nullable()->after('telegram_unverified_register_sent_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_unverified_register_sent_at',
                'telegram_verified_register_sent_at',
            ]);
        });
    }
};

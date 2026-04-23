<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->json('supported_payment_methods')
                ->nullable()
                ->after('target_type');
        });

        DB::table('coupons')
            ->whereNull('supported_payment_methods')
            ->update([
                'supported_payment_methods' => json_encode(['fib'], JSON_THROW_ON_ERROR),
            ]);
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('supported_payment_methods');
        });
    }
};

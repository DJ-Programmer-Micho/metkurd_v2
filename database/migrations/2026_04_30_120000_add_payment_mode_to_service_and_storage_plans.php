<?php

use App\Domain\Payments\Enums\PaymentMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_plans') && ! Schema::hasColumn('service_plans', 'payment_mode')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->string('payment_mode', 30)
                    ->default(PaymentMode::RECURRING->value)
                    ->after('billing_interval')
                    ->index();
            });
        }

        if (Schema::hasTable('storage_plans') && ! Schema::hasColumn('storage_plans', 'payment_mode')) {
            Schema::table('storage_plans', function (Blueprint $table) {
                $table->string('payment_mode', 30)
                    ->default(PaymentMode::RECURRING->value)
                    ->after('price_iqd')
                    ->index();
            });
        }

        if (Schema::hasTable('service_plans') && Schema::hasColumn('service_plans', 'payment_mode')) {
            DB::table('service_plans')
                ->whereNull('payment_mode')
                ->orWhereRaw("TRIM(payment_mode) = ''")
                ->orWhereNotIn('payment_mode', [PaymentMode::ONE_TIME->value, PaymentMode::RECURRING->value])
                ->update([
                    'payment_mode' => PaymentMode::RECURRING->value,
                ]);
        }

        if (Schema::hasTable('storage_plans') && Schema::hasColumn('storage_plans', 'payment_mode')) {
            DB::table('storage_plans')
                ->whereNull('payment_mode')
                ->orWhereRaw("TRIM(payment_mode) = ''")
                ->orWhereNotIn('payment_mode', [PaymentMode::ONE_TIME->value, PaymentMode::RECURRING->value])
                ->update([
                    'payment_mode' => PaymentMode::RECURRING->value,
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_plans') && Schema::hasColumn('service_plans', 'payment_mode')) {
            Schema::table('service_plans', function (Blueprint $table) {
                $table->dropColumn('payment_mode');
            });
        }

        if (Schema::hasTable('storage_plans') && Schema::hasColumn('storage_plans', 'payment_mode')) {
            Schema::table('storage_plans', function (Blueprint $table) {
                $table->dropColumn('payment_mode');
            });
        }
    }
};

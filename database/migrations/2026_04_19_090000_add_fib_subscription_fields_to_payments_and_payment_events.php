<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_object_type', 30)->default('payment')->after('payment_mode')->index();
            $table->string('fib_subscription_id', 190)->nullable()->unique()->after('fib_payment_id');
            $table->string('provider_payment_status', 80)->nullable()->after('fib_subscription_id')->index();
            $table->string('provider_subscription_status', 80)->nullable()->after('provider_payment_status')->index();
            $table->string('provider_interval', 80)->nullable()->after('provider_subscription_status');
            $table->string('provider_trial_period', 80)->nullable()->after('provider_interval');
            $table->timestamp('active_until')->nullable()->after('valid_until')->index();
            $table->timestamp('last_payment_at')->nullable()->after('active_until')->index();
        });

        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('provider_object_type', 30)->nullable()->after('source')->index();
            $table->string('fib_subscription_id', 190)->nullable()->after('fib_payment_id')->index();
        });

        DB::table('payments')
            ->whereNull('provider_object_type')
            ->update(['provider_object_type' => 'payment']);

        DB::table('payments')
            ->whereNotNull('provider_status')
            ->update(['provider_payment_status' => DB::raw('provider_status')]);

        DB::table('payment_events')
            ->whereNull('provider_object_type')
            ->update(['provider_object_type' => 'payment']);
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropColumn([
                'provider_object_type',
                'fib_subscription_id',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'provider_object_type',
                'fib_subscription_id',
                'provider_payment_status',
                'provider_subscription_status',
                'provider_interval',
                'provider_trial_period',
                'active_until',
                'last_payment_at',
            ]);
        });
    }
};

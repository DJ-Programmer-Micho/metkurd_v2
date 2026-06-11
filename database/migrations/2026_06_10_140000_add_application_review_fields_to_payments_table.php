<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('internal_status', 50)->default(PaymentInternalStatus::PENDING->value)->after('status')->index();
            $table->string('mismatch_reason', 255)->nullable()->after('status_reason');
            $table->timestamp('review_required_at')->nullable()->after('fulfilled_at')->index();
            $table->timestamp('failed_at')->nullable()->after('review_required_at')->index();
        });

        DB::table('payments')->update([
            'internal_status' => DB::raw("
                CASE
                    WHEN fulfilled_at IS NOT NULL THEN 'applied'
                    WHEN status = 'paid' THEN 'paid_pending_application'
                    WHEN status = 'awaiting_customer_action' THEN 'awaiting_customer_action'
                    WHEN status = 'failed' THEN 'failed'
                    WHEN status = 'canceled' THEN 'canceled'
                    WHEN status = 'expired' THEN 'expired'
                    WHEN status = 'refund_requested' THEN 'refund_requested'
                    WHEN status = 'refunded' THEN 'refunded'
                    ELSE 'pending'
                END
            "),
        ]);

        DB::table('payments')
            ->whereNotNull('fulfilled_at')
            ->whereNull('review_required_at')
            ->where('internal_status', 'applied')
            ->update([
                'review_required_at' => null,
            ]);

        DB::table('payments')
            ->whereIn('status', ['failed', 'canceled', 'expired'])
            ->whereNull('failed_at')
            ->update([
                'failed_at' => DB::raw('COALESCE(expired_at, canceled_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'internal_status',
                'mismatch_reason',
                'review_required_at',
                'failed_at',
            ]);
        });
    }
};

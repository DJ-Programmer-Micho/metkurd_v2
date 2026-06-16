<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_files', 'retention_mode')) {
                $table->string('retention_mode', 20)->nullable()->after('status')->index();
            }

            if (! Schema::hasColumn('customer_files', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('retention_mode')->index();
            }

            if (! Schema::hasColumn('customer_files', 'delete_reason')) {
                $table->string('delete_reason', 60)->nullable()->after('deleted_at');
            }

            if (! Schema::hasColumn('customer_files', 'source_type')) {
                $table->string('source_type', 60)->nullable()->after('delete_reason')->index();
            }

            if (! Schema::hasColumn('customer_files', 'source_id')) {
                $table->string('source_id', 80)->nullable()->after('source_type')->index();
            }

            if (! Schema::hasColumn('customer_files', 'counts_toward_quota')) {
                $table->boolean('counts_toward_quota')->default(true)->after('source_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_files', function (Blueprint $table): void {
            foreach ([
                'counts_toward_quota',
                'source_id',
                'source_type',
                'delete_reason',
                'expires_at',
                'retention_mode',
            ] as $column) {
                if (Schema::hasColumn('customer_files', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

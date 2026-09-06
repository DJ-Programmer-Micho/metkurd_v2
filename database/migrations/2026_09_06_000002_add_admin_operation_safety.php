<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'status')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedTinyInteger('status')->default(1));
        }
        Schema::table('users', fn (Blueprint $table) => $table->json('admin_capabilities')->nullable());

        Schema::create('admin_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('payload_hash', 64);
            $table->json('requested');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->uuid('operation_id')->nullable()->index();
            $table->string('action', 120);
            $table->string('target_type', 191);
            $table->string('target_id', 191)->nullable()->index();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('requested')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
        Schema::dropIfExists('admin_operations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_capabilities'));
        // Retain status: it may predate this migration in an existing deployment.
    }
};

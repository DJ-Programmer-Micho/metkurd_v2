<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('provider', 40)->index();
            $table->string('event_type', 60)->index();
            $table->string('source', 40)->index();
            $table->string('event_key', 190)->nullable()->unique();
            $table->string('local_reference', 120)->nullable()->index();
            $table->string('fib_payment_id', 190)->nullable()->index();
            $table->string('before_status', 30)->nullable()->index();
            $table->string('after_status', 30)->nullable()->index();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->index(['provider', 'event_type', 'created_at'], 'payment_events_provider_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};

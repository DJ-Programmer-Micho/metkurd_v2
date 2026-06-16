<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_entitlements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('tool_action_id')->index();

            // null means "no override", true/false forces allow/deny
            $table->boolean('allowed')->nullable();

            $table->json('limits')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('tool_action_id')->references('id')->on('tool_actions')->cascadeOnDelete();

            $table->unique(['customer_id', 'tool_action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_entitlements');
    }
};

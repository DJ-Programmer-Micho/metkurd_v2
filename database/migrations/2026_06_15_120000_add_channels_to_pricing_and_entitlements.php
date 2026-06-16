<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_rules') && ! Schema::hasColumn('pricing_rules', 'pricing_channel')) {
            Schema::table('pricing_rules', function (Blueprint $table): void {
                $table->string('pricing_channel', 20)->default('all')->after('service_plan_id');
            });

            Schema::table('pricing_rules', function (Blueprint $table): void {
                $table->index(
                    ['tool_action_id', 'service_plan_id', 'pricing_channel', 'is_active', 'priority'],
                    'pr_channel_lookup_idx'
                );
            });
        }

        if (Schema::hasTable('customer_pricing_rules') && ! Schema::hasColumn('customer_pricing_rules', 'pricing_channel')) {
            Schema::table('customer_pricing_rules', function (Blueprint $table): void {
                $table->string('pricing_channel', 20)->default('all')->after('tool_action_id');
            });

            Schema::table('customer_pricing_rules', function (Blueprint $table): void {
                $table->index(
                    ['customer_id', 'tool_action_id', 'pricing_channel', 'is_active', 'priority'],
                    'cpr_channel_lookup_idx'
                );
            });
        }

        if (Schema::hasTable('plan_entitlements') && ! Schema::hasColumn('plan_entitlements', 'entitlement_channel')) {
            Schema::table('plan_entitlements', function (Blueprint $table): void {
                $table->string('entitlement_channel', 20)->default('all')->after('tool_action_id');
            });

            Schema::table('plan_entitlements', function (Blueprint $table): void {
                $table->dropUnique('plan_entitlements_service_plan_id_tool_action_id_unique');
                $table->unique(
                    ['service_plan_id', 'tool_action_id', 'entitlement_channel'],
                    'plan_entitlements_plan_action_channel_unique'
                );
                $table->index(
                    ['service_plan_id', 'tool_action_id', 'entitlement_channel'],
                    'plan_entitlements_channel_lookup_idx'
                );
            });
        }

        if (Schema::hasTable('customer_entitlements') && ! Schema::hasColumn('customer_entitlements', 'entitlement_channel')) {
            Schema::table('customer_entitlements', function (Blueprint $table): void {
                $table->string('entitlement_channel', 20)->default('all')->after('tool_action_id');
            });

            Schema::table('customer_entitlements', function (Blueprint $table): void {
                $table->dropUnique('customer_entitlements_customer_id_tool_action_id_unique');
                $table->unique(
                    ['customer_id', 'tool_action_id', 'entitlement_channel'],
                    'customer_entitlements_customer_action_channel_unique'
                );
                $table->index(
                    ['customer_id', 'tool_action_id', 'entitlement_channel'],
                    'customer_entitlements_channel_lookup_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_entitlements') && Schema::hasColumn('customer_entitlements', 'entitlement_channel')) {
            Schema::table('customer_entitlements', function (Blueprint $table): void {
                $table->dropIndex('customer_entitlements_channel_lookup_idx');
                $table->dropUnique('customer_entitlements_customer_action_channel_unique');
                $table->unique(
                    ['customer_id', 'tool_action_id'],
                    'customer_entitlements_customer_id_tool_action_id_unique'
                );
                $table->dropColumn('entitlement_channel');
            });
        }

        if (Schema::hasTable('plan_entitlements') && Schema::hasColumn('plan_entitlements', 'entitlement_channel')) {
            Schema::table('plan_entitlements', function (Blueprint $table): void {
                $table->dropIndex('plan_entitlements_channel_lookup_idx');
                $table->dropUnique('plan_entitlements_plan_action_channel_unique');
                $table->unique(
                    ['service_plan_id', 'tool_action_id'],
                    'plan_entitlements_service_plan_id_tool_action_id_unique'
                );
                $table->dropColumn('entitlement_channel');
            });
        }

        if (Schema::hasTable('customer_pricing_rules') && Schema::hasColumn('customer_pricing_rules', 'pricing_channel')) {
            Schema::table('customer_pricing_rules', function (Blueprint $table): void {
                $table->dropIndex('cpr_channel_lookup_idx');
                $table->dropColumn('pricing_channel');
            });
        }

        if (Schema::hasTable('pricing_rules') && Schema::hasColumn('pricing_rules', 'pricing_channel')) {
            Schema::table('pricing_rules', function (Blueprint $table): void {
                $table->dropIndex('pr_channel_lookup_idx');
                $table->dropColumn('pricing_channel');
            });
        }
    }
};

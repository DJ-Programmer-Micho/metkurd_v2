<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $page = DB::table('landing_tool_pages')->where('slug', 'translation')->lockForUpdate()->first();
            if (! $page || ! $page->is_active) {
                return;
            }

            // Preserve all editorial, media, demo and historical fields, including timestamps.
            DB::table('landing_tool_pages')->where('id', $page->id)->where('slug', 'translation')
                ->where('is_active', true)->update(['is_active' => false]);
            DB::table('admin_audit_events')->insert([
                'admin_id' => null,
                'operation_id' => (string) Str::uuid(),
                'action' => 'landing.translation.retired',
                'target_type' => 'App\\Models\\LandingToolPage',
                'target_id' => (string) $page->id,
                'reason' => 'Reviewed data migration: Translation is retired from the current V2 public catalog; retain its editorial row.',
                'before_state' => json_encode(['slug' => 'translation', 'is_active' => true]),
                'requested' => json_encode(['is_active' => false]),
                'after_state' => json_encode(['slug' => 'translation', 'is_active' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Rolling back code must not republish a retired product or remove its audit history.
    }
};

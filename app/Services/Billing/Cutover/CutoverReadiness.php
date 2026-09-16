<?php

namespace App\Services\Billing\Cutover;

use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class CutoverReadiness
{
    public function inspect(string $target, ?int $adminId): array
    {
        $ran = Schema::hasTable('migrations') ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all() : [];
        $files = array_keys(app('migrator')->getMigrationFiles(database_path('migrations')));
        $migrations = ['ran' => $ran, 'pending' => array_values(array_diff($files, $ran)), 'unknown' => array_values(array_diff($ran, $files))];
        $admin = $adminId && Schema::hasColumns('users', ['status', 'admin_capabilities']) ? User::find($adminId) : null;
        $capabilities = collect(AdminAccess::CAPABILITIES)->mapWithKeys(fn ($capability) => [$capability => $admin && Gate::forUser($admin)->allows($capability)])->all();
        $backup = ['backup_reference' => config('billing_cutover.backup_reference'), 'restore_reference' => config('billing_cutover.restore_reference')];
        $blockers = [];
        if ($target === 'production') {
            if ($migrations['pending'] || $migrations['unknown']) {
                $blockers[] = 'Production migration inventory does not match this release.';
            }
            if (in_array(false, $capabilities, true)) {
                $blockers[] = 'Production Admin preflight requires an active operator with every reported Admin capability; no permissions are provisioned automatically.';
            }
            if (! filled($backup['backup_reference']) || ! filled($backup['restore_reference'])) {
                $blockers[] = 'Production backup and tested restore evidence references must be configured before final review.';
            }
        }

        return ['migrations' => $migrations, 'admin' => ['id' => $adminId, 'active' => $admin && (int) $admin->status === 1, 'capabilities' => $capabilities],
            'backup_evidence' => $backup, 'blockers' => $blockers];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class AuditAdminCapabilities extends Command
{
    protected $signature = 'admin:capability-audit {user : Admin user ID}';

    protected $description = 'Read-only active Admin capability audit; never grants privileges.';

    public function handle(): int
    {
        if (! Schema::hasColumns('users', ['status', 'admin_capabilities']) || ! ctype_digit((string) $this->argument('user'))) {
            $this->error('Required Admin schema or numeric user ID is missing.');

            return self::FAILURE;
        }
        $user = User::find($this->argument('user'));
        $checks = collect(AdminAccess::CAPABILITIES)->mapWithKeys(fn ($capability) => [$capability => $user && Gate::forUser($user)->allows($capability)])->all();
        $this->line(json_encode(['user_id' => $user?->id, 'active' => (int) $user?->status === 1,
            'capabilities' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}

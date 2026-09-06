<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\AdminAudit;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetAdminCapabilities extends Command
{
    protected $signature = 'admin:capabilities {user : Existing numeric Admin user ID} {capabilities* : Explicit capabilities; admin.read for support only} {--reason= : Required provisioning reason}';

    protected $description = 'Provision an existing Admin capability list through the trusted deployment console';

    public function handle(): int
    {
        $capabilities = array_values(array_unique($this->argument('capabilities')));
        $reason = trim((string) $this->option('reason'));
        if (! ctype_digit((string) $this->argument('user')) || strlen($reason) < 10
            || array_diff($capabilities, AdminAccess::CAPABILITIES)) {
            $this->error('Specify an existing numeric user ID, supported capabilities and a reason of at least 10 characters.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($capabilities, $reason) {
            $user = User::query()->lockForUpdate()->findOrFail($this->argument('user'));
            $before = ['admin_capabilities' => $user->admin_capabilities];
            $user->forceFill(['admin_capabilities' => $capabilities])->save();
            $audit = app(AdminAudit::class);
            $audit->operationId = (string) Str::uuid();
            $audit->reason = $reason;
            $audit->record('console.capabilities', User::class, $user->id, $before,
                ['admin_capabilities' => $capabilities, 'actor_source' => 'deployment_console'], ['admin_capabilities' => $user->admin_capabilities]);
        });
        $this->info('Capabilities saved. Account active status was not changed.');

        return self::SUCCESS;
    }
}

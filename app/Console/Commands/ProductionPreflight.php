<?php

namespace App\Console\Commands;

use App\Domain\Payments\Models\Payment;
use App\Models\CreditOrder;
use App\Models\ToolAction;
use App\Services\Billing\BillingReportingBoundary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Static configuration and database reads only; no remote probes or lifecycle services. */
class ProductionPreflight extends Command
{
    protected $signature = 'metkurd:production-preflight {--production : Require production profile and native MySQL} {--require-epoch : Require an existing committed billing cutover}';

    protected $description = 'Read-only release checks; does not certify workers, scheduler, callbacks or deployment.';

    public function handle(): int
    {
        try {
            $db = DB::connection();
            $version = $db->getDriverName() === 'mysql' ? DB::selectOne('SELECT VERSION() AS version')->version : $db->getDriverName();
            $ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $files = array_keys(app('migrator')->getMigrationFiles(database_path('migrations')));
            $pending = array_values(array_diff($files, $ran));
            $unknown = array_values(array_diff($ran, $files));
            $checks = ['migration_inventory' => $pending === [] && $unknown === [],
                'admin_schema' => Schema::hasColumns('users', ['status', 'admin_capabilities']) && Schema::hasTable('admin_operations') && Schema::hasTable('admin_audit_events'),
                'allocation_schema' => Schema::hasTable('subscription_credit_allocations'),
                'agreement_schema' => Schema::hasTable('service_plan_agreements')];
            $actions = ['xomni.generate', 'xomni-v2.generate', 'clone_xomni.generate', 'vector-v2.generate', 'leo.transcribe', 'caption.standard', 'ocr.standard', 'stem.sep2', 'stem.sep4'];
            foreach ($actions as $code) {
                $checks['action:'.$code] = Schema::hasTable('tool_actions') && ToolAction::where('full_code', $code)->where('is_active', true)
                    ->whereHas('tool', fn ($q) => $q->where('is_active', true))->exists();
            }
            $epoch = app(BillingReportingBoundary::class)->current();
            if ($this->option('require-epoch')) {
                $checks['billing_epoch'] = $epoch !== null;
            }
            if ($this->option('production')) {
                $checks['production_environment'] = app()->environment('production') && ! config('app.debug');
                $checks['native_mysql'] = $db->getDriverName() === 'mysql' && ! str_contains(strtolower($version), 'mariadb');
                $checks['fib_profile'] = config('fib.environment') === 'production' && config('fib.enabled');
                $checks['https_callback'] = parse_url(config('fib.callback_base_url'), PHP_URL_SCHEME) === 'https';
                foreach (['payment', 'subscription'] as $profile) {
                    $checks['fib_credentials:'.$profile] = filled(config('fib.profiles.'.$profile.'.client_id')) && filled(config('fib.profiles.'.$profile.'.client_secret'));
                }
                $checks['async_queue'] = ! in_array(config('queue.default'), ['sync', 'null', 'deferred', 'background'], true);
            }
            $this->line(json_encode(['scope' => 'configuration and database only', 'environment' => app()->environment(),
                'database' => ['driver' => $db->getDriverName(), 'schema' => $db->getDatabaseName(), 'version' => $version],
                'pending_migrations' => $pending, 'unknown_migrations' => $unknown, 'checks' => $checks,
                'billing_epoch' => $epoch, 'current_payments' => Schema::hasTable('payments') ? Payment::currentBillingPeriod()->count() : null,
                'current_paid_orders' => Schema::hasTable('credit_orders') ? CreditOrder::currentBillingPeriod()->revenueIncluded()->where('status', 'paid')->count() : null,
                'gates' => ['app_v2' => config('metkurd_v2.enabled'), 'api_v2' => config('customer_api.v2_enabled'), 'fib_reconciliation' => config('fib.reconciliation.enabled')],
                'queue' => config('queue.default'), 'unverified' => ['production deployment revision', 'worker processes', 'scheduler execution', 'callback reachability and delivery', 'backups and restore rehearsal', 'native MySQL DDL acceptance']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Read-only preflight could not complete ('.class_basename($exception).'). Check schema/configuration; no operations were executed.');

            return self::FAILURE;
        }
    }
}

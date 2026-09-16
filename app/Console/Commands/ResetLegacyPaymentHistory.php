<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminAudit;
use App\Services\Billing\LegacyPaymentHistoryReset;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ResetLegacyPaymentHistory extends Command
{
    protected $signature = 'billing:reset-legacy-payment-history
        {--before= : Exclusive created-at cutoff, YYYY-MM-DD HH:MM:SS in application timezone}
        {--dry-run : Read-only inventory and exact candidate IDs (default)}
        {--execute : Execute the reviewed, unblocked subset}
        {--confirm= : Must equal RESET-LEGACY-PAYMENT-HISTORY}
        {--review-hash= : Hash from the reviewed dry run}
        {--admin= : Active operator user with admin.finance and admin.reconcile}
        {--reason= : Audit reason, at least 10 characters}
        {--workers-stopped : Operator attests all queue workers and schedulers are stopped}';

    protected $description = 'Review legacy payment history; guarded retirement of abandoned attempts only, never balances or purchases.';

    public function handle(LegacyPaymentHistoryReset $reset): int
    {
        $connection = DB::connection();
        $local = app()->environment('local') && $connection->getDriverName() === 'mysql'
            && $connection->getConfig('host') === '127.0.0.1' && (string) $connection->getConfig('port') === '3306'
            && $connection->getDatabaseName() === 'metkurd_local_260906'
            && ! $connection->getConfig('read') && ! $connection->getConfig('write')
            && ! $connection->getConfig('unix_socket') && ! $connection->getConfig('prefix');
        $isolated = app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        if (! $local && ! $isolated) {
            $this->error('Refused: only the explicitly designated local database or isolated in-memory tests are allowed.');

            return self::FAILURE;
        }
        $before = (string) $this->option('before');
        if (Validator::make(['before' => $before], ['before' => 'required|date_format:Y-m-d H:i:s|before:now'])->fails()) {
            $this->error('Specify a past --before="YYYY-MM-DD HH:MM:SS" cutoff. Old architecture is not inferred from age alone.');

            return self::FAILURE;
        }
        if (! $this->option('execute')) {
            $review = DB::transaction(fn () => $reset->inspect($before));
            $this->line(json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('DRY RUN: nothing deleted. Candidate IDs are not executable while blockers exist.');

            return $review['blockers'] ? self::FAILURE : self::SUCCESS;
        }
        if ($this->option('dry-run') || $this->option('confirm') !== 'RESET-LEGACY-PAYMENT-HISTORY'
            || ! $this->option('workers-stopped') || (! $isolated && ! app()->isDownForMaintenance())
            || Validator::make(['reason' => $this->option('reason'), 'hash' => $this->option('review-hash'), 'admin' => $this->option('admin')],
                ['reason' => 'required|string|min:10|max:1000', 'hash' => 'required|regex:/^[a-f0-9]{64}$/', 'admin' => 'required|integer|min:1'])->fails()) {
            $this->error('Refused: execution requires confirmation, reviewed hash, operator/reason, maintenance mode and stopped workers.');

            return self::FAILURE;
        }
        if (! auth('admin')->onceUsingId((int) $this->option('admin'))) {
            $this->error('Operator not found.');

            return self::FAILURE;
        }
        AdminAccess::authorize('admin.finance');
        AdminAccess::authorize('admin.reconcile');
        if ($local && collect(Schema::getTables())->contains(fn ($table) => ($table['engine'] ?? null) !== 'InnoDB')) {
            $this->error('Refused: every table must support transactional rollback.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($reset, $before) {
            // Maintenance-only operation. Lock customers first, then financial parents and their dependents.
            foreach (array_unique(['customers', 'payments', 'payment_intents', ...Schema::getTableListing(schemaQualified: false)]) as $table) {
                DB::table($table)->lockForUpdate()->get();
            }
            AdminAccess::authorize('admin.finance');
            AdminAccess::authorize('admin.reconcile');
            $review = $reset->inspect($before);
            $this->line(json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($review['blockers'] || ! hash_equals($review['review_hash'], (string) $this->option('review-hash'))) {
                $this->error('Refused: blockers remain or the reviewed inventory changed. Run dry-run again.');

                return self::FAILURE;
            }
            $reset->deleteReviewed($review);
            $audit = app(AdminAudit::class);
            $audit->reason = (string) $this->option('reason');
            $audit->record('billing.reset_legacy_payment_history', self::class, null, $review['counts'],
                ['before' => $before, 'review_hash' => $review['review_hash']], ['deleted_ids' => $review['candidate_delete_ids']]);
            $this->info('Reviewed abandoned attempts removed. Purchases, customers, balances and usage were retained.');

            return self::SUCCESS;
        });
    }
}

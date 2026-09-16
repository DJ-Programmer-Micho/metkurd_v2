<?php

namespace App\Console\Commands;

use App\Services\Billing\PaymentHistoryReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ResetPaymentHistory extends Command
{
    protected $signature = 'billing:reset-payment-history
        {--dry-run : Review only (default)}
        {--execute : Execute the exact unblocked review}
        {--review-hash= : Hash binding the latest active Payment and reviewed database state}
        {--confirm= : Must equal RESET-PAYMENT-HISTORY}
        {--admin= : Active Admin with finance and reconcile capabilities}
        {--reason= : Reset audit reason, 10-1000 characters}
        {--workers-stopped : Attest all workers, schedulers and provider callbacks are stopped}';

    protected $description = 'Review a payment-history reset retaining the latest active Payment and customer financial state.';

    public function handle(PaymentHistoryReset $reset): int
    {
        $guard = auth('admin');
        $previous = $guard->user();
        try {
            if (! $this->option('execute')) {
                $this->info('Reviewing the latest active Payment and database history (read-only). Large histories may take a few minutes.');
                $review = $reset->review();
                $this->line(json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->info('DRY RUN: no writes. The latest active Payment is retained automatically; the review hash locks this selection.');

                return $review['blockers'] ? self::FAILURE : self::SUCCESS;
            }
            if ($this->option('dry-run') || $this->option('confirm') !== 'RESET-PAYMENT-HISTORY'
                || ! $this->option('workers-stopped')
                || Validator::make(['hash' => $this->option('review-hash'), 'admin' => $this->option('admin'), 'reason' => trim((string) $this->option('reason'))],
                    ['hash' => 'required|regex:/^[a-f0-9]{64}$/', 'admin' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:1000'])->fails()) {
                throw new \App\Services\Billing\PaymentHistoryResetRefused('Execution requires review hash, confirmation, Admin, reason and --workers-stopped; --dry-run cannot be combined with --execute.');
            }
            if (! $guard->onceUsingId((int) $this->option('admin'))) {
                throw new \App\Services\Billing\PaymentHistoryResetRefused('Admin not found.');
            }
            $result = $reset->execute((string) $this->option('review-hash'), trim((string) $this->option('reason')), true);
            $this->info('Reset committed. Selected Payment and its events are unchanged; customer financial state is preserved.');
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->error('Refused: an active Admin with admin.finance and admin.reconcile is required.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? 'unknown');
            $code = (string) ($exception->errorInfo[1] ?? 'unknown');
            // Error codes are useful to the operator; SQL, bindings and driver messages may contain secrets.
            $this->error('Database inventory/reset query failed (SQLSTATE '.preg_replace('/[^A-Z0-9]/', '', $state)
                .', driver code '.preg_replace('/[^0-9]/', '', $code).'). No reset committed. Verify the configured schema and required migrations.');
        } catch (\Throwable $exception) {
            // SQL/provider exception text can contain confidential bindings. Never print it.
            $this->error($exception instanceof \App\Services\Billing\PaymentHistoryResetRefused
                ? $exception->getMessage() : 'Reset refused or failed; no reset committed. Check options, maintenance, database support and the reviewed inventory.');
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }

        return self::FAILURE;
    }
}

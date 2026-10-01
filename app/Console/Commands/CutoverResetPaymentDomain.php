<?php

namespace App\Console\Commands;

use App\Services\Billing\PaymentDomainCutover;
use App\Services\Billing\PaymentHistoryResetRefused;
use Illuminate\Console\Command;

class CutoverResetPaymentDomain extends Command
{
    protected $signature = 'billing:cutover-reset-payment-domain
        {--target= : Required: local-rehearsal or production; must match deployment assertions}
        {--mode=preserve-access : preserve-access or explicit full-local-reset (every customer becomes Free)}
        {--dry-run : Read-only review (default)}
        {--execute : Execute the reviewed target cutover}
        {--review-hash= : Exact dry-run hash}
        {--confirm= : Exact target-specific confirmation phrase}
        {--admin= : Active finance and reconcile Admin ID}
        {--reason= : Audit reason (10-1000 characters)}
        {--workers-stopped : Attest workers, schedulers, callbacks and other writers are stopped}
        {--backup-confirmed : Confirm the configured target backup belongs to this target and is available}
        {--restore-confirmed : Confirm the configured target restore rehearsal evidence has been reviewed}';

    protected $description = 'Review or execute one V2 payment-domain cutover using an explicitly authorized deployment target.';

    public function handle(PaymentDomainCutover $cutover): int
    {
        $guard = auth('admin');
        $previous = $guard->user();
        try {
            $mode = (string) $this->option('mode');
            $target = (string) $this->option('target');
            $cutover->identity($target);
            $operator = $this->option('admin') ?? config('billing_cutover.admin_id');
            $adminId = ctype_digit((string) $operator) ? (int) $operator : null;
            // Compatibility local review may omit Admin; full reset and production bind the operator.
            $reviewAdmin = ($target === 'production' || $mode === PaymentDomainCutover::FULL_LOCAL_RESET) ? $adminId : null;
            if (! $this->option('execute')) {
                $review = $cutover->review($target, $reviewAdmin, $mode);
                $this->line(json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->info('DRY RUN: no writes. Review this private safety report before execution.');

                return $review['blockers'] ? self::FAILURE : self::SUCCESS;
            }
            $confirmation = $target === 'production' ? 'RESET-V2-PRODUCTION-BILLING-DOMAIN' : 'RESET-V2-BILLING-DOMAIN';
            if ($mode === PaymentDomainCutover::FULL_LOCAL_RESET) {
                $confirmation .= '-ALL-CUSTOMERS-FREE';
            }
            if ($this->option('dry-run') || $this->option('confirm') !== $confirmation
                || ! $this->option('workers-stopped') || ! $adminId
                || ! $guard->onceUsingId($adminId)) {
                throw new PaymentHistoryResetRefused('Execution requires exact confirmation, Admin identity and stopped-writers attestation; do not combine --dry-run with --execute.');
            }
            $result = $cutover->execute($target, (string) $this->option('review-hash'), trim((string) $this->option('reason')), true,
                $reviewAdmin, (bool) $this->option('backup-confirmed'), (bool) $this->option('restore-confirmed'), $mode);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->info('Reviewed target cutover committed; exact preservation and current zero-revenue checks passed.');

            return self::SUCCESS;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->error('Refused: active admin.finance and admin.reconcile are required.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->error('Database check failed (SQLSTATE '.preg_replace('/[^A-Z0-9]/', '', (string) ($exception->errorInfo[0] ?? 'unknown')).'). No cutover committed.');
        } catch (\Throwable $exception) {
            $this->error($exception instanceof PaymentHistoryResetRefused ? $exception->getMessage() : 'Cutover check failed. No cutover committed; inspect required schema and isolated regressions.');
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }

        return self::FAILURE;
    }
}

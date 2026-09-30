<?php

namespace App\Console\Commands;

use App\Services\Billing\ProviderObligationBatchActions;
use App\Services\Billing\ProviderReviewFiles;
use Illuminate\Console\Command;

class ApplyProviderObligations extends Command
{
    protected $signature = 'billing:provider-obligations-apply
        {--manifest= : Private worksheet JSON with selected individual decisions}
        {--review-hash= : Exact packet hash returned by this command dry run}
        {--merchant-import= : Private operator-attested return JSON; prepares a new worksheet, never executes}
        {--admin=} {--operation= : Durable parent UUID} {--reason=}
        {--dry-run : Read-only validation (default); no HTTP}
        {--execute : Apply the exact selected actions atomically}';

    protected $description = 'Preview or atomically apply bounded GET-only, merchant-attested and individual coverage review actions.';

    public function handle(ProviderObligationBatchActions $actions, ProviderReviewFiles $files): int
    {
        $guard = auth('admin');
        $previous = $guard->user();
        try {
            if (! ctype_digit((string) $this->option('admin')) || ! $guard->onceUsingId((int) $this->option('admin'))
                || ($this->option('execute') && ($this->option('dry-run') || $this->option('merchant-import')))) {
                throw new \RuntimeException;
            }
            \App\Support\Admin\AdminAccess::authorize('admin.finance');
            \App\Support\Admin\AdminAccess::authorize('admin.reconcile');
            $packet = $files->read((string) $this->option('manifest'));
            if ($this->option('merchant-import')) {
                $packet = app(\App\Services\Billing\ProviderObligationBatchReview::class)->importMerchant($packet, $files->read((string) $this->option('merchant-import')));
            }
            $result = $actions->apply($packet, (string) $this->option('operation'),
                (string) $this->option('reason'), $this->option('review-hash'), (bool) $this->option('execute'));
            if ($this->option('merchant-import')) {
                $result['prepared_worksheet'] = $files->write($packet, 'merchant-reviewed-worksheet');
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->warn('Reviewed is not necessarily retired. Export a fresh inventory after execution; cutover still requires zero blockers.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Batch refused or rolled back. Check exact manifest/hash, identities, capabilities, per-item evidence and configured limits. No cutover was authorized.');

            return self::FAILURE;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}

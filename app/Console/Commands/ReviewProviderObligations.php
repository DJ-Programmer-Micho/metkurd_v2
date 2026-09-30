<?php

namespace App\Console\Commands;

use App\Services\Billing\ProviderObligationBatchReview;
use App\Services\Billing\ProviderReviewFiles;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

class ReviewProviderObligations extends Command
{
    protected $signature = 'billing:provider-obligations-review
        {--target= : Configured local-rehearsal or production identity}
        {--admin= : Active finance and reconcile Admin}
        {--details : Show allowlisted private review identities}
        {--export : Write immutable source review and a separate editable worksheet under private billing storage}
        {--merchant-export : Also export compact unresolved provider IDs without customer PII}';

    protected $description = 'Read-only grouped provider obligation review; no provider calls or financial writes.';

    public function handle(ProviderObligationBatchReview $service, ProviderReviewFiles $files): int
    {
        $guard = auth('admin');
        $previous = $guard->user();
        try {
            if (! ctype_digit((string) $this->option('admin')) || ! $guard->onceUsingId((int) $this->option('admin'))) {
                throw new \RuntimeException;
            }
            AdminAccess::authorize('admin.finance');
            AdminAccess::authorize('admin.reconcile');
            $packet = $service->review((string) ($this->option('target') ?: config('billing_cutover.target')));
            $output = ['manifest_hash' => $packet['review']['manifest_hash'], 'counts' => $packet['review']['counts'], 'cutover_authorized' => false];
            if ($this->option('details')) {
                $output['items'] = $packet['review']['items'];
            }
            if ($this->option('export')) {
                $output['source_file'] = $files->write($packet, 'source');
                $output['worksheet_file'] = $files->write($service->worksheet($packet), 'worksheet');
            }
            if ($this->option('merchant-export')) {
                $output['merchant_file'] = $files->write($service->merchantExport($packet), 'merchant');
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Provider review refused: check configured target identity, schema, release and finance/reconcile access. No provider action was authorized.');

            return self::FAILURE;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}

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
        {--remote-limit= : Prepare the next 1–25 exact objects for GET-first remote retirement}
        {--retry-reviewed : Include previously reviewed objects; a prior POST marker still prohibits another POST}
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
            $worksheet = $service->worksheet($packet);
            if ($this->option('remote-limit') !== null) {
                if (! $this->option('export') || ! ctype_digit((string) $this->option('remote-limit'))) {
                    throw new \RuntimeException;
                }
                $worksheet = $service->selectRemote($packet, (int) $this->option('remote-limit'), (bool) $this->option('retry-reviewed'));
            }
            $output = ['manifest_hash' => $packet['review']['manifest_hash'], 'counts' => $packet['review']['counts'], 'cutover_authorized' => false];
            if ($this->option('details')) {
                $output['items'] = $packet['review']['items'];
            }
            if ($this->option('export')) {
                $output['source_file'] = $files->write($packet, 'source');
                $output['worksheet_file'] = $files->write($worksheet, 'worksheet');
                $output['selected_objects'] = count(array_filter($worksheet['decisions'], fn ($d) => $d['selected']));
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

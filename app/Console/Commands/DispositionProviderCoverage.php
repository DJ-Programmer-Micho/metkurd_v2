<?php

namespace App\Console\Commands;

use App\Services\Billing\ProviderCoverageDispositions;
use Illuminate\Console\Command;

class DispositionProviderCoverage extends Command
{
    protected $signature = 'billing:disposition-provider-coverage
        {payment : Exact original Payment ID}
        {--customer=} {--provider-subscription=} {--subscription-kind=} {--subscription=}
        {--coverage-start=} {--coverage-end=} {--evidence-event=}
        {--coverage-confirmed : Merchant/provider review explicitly confirms the entire supplied paid interval}
        {--review-reference= : Non-secret merchant/provider review reference}
        {--admin=} {--operation=} {--reason=}
        {--dry-run : Read-only review (default)} {--execute : Store the audited bounded approval}';

    protected $description = 'Approve one evidenced legacy provider coverage term without HTTP, credits, payment changes or cutover.';

    public function handle(ProviderCoverageDispositions $service): int
    {
        $guard = auth('admin');
        $previous = $guard->user();
        try {
            if (! ctype_digit((string) $this->option('admin')) || ! $guard->onceUsingId((int) $this->option('admin'))
                || ($this->option('execute') && $this->option('dry-run'))) {
                throw new \RuntimeException;
            }
            $request = ['payment_id' => $this->argument('payment'), 'customer_id' => $this->option('customer'),
                'provider_subscription_id' => $this->option('provider-subscription'),
                'subscription_kind' => $this->option('subscription-kind'), 'subscription_id' => $this->option('subscription'),
                'coverage_start' => $this->option('coverage-start'), 'coverage_end' => $this->option('coverage-end'),
                'evidence_event_id' => $this->option('evidence-event'), 'coverage_confirmed' => $this->option('coverage-confirmed'),
                'review_reference' => $this->option('review-reference')];
            // Stable JSON identity across CLI replay and service callers.
            foreach (['payment_id', 'customer_id', 'subscription_id', 'evidence_event_id'] as $key) {
                if (! ctype_digit((string) $request[$key]) || (int) $request[$key] < 1) {
                    throw new \RuntimeException;
                }
                $request[$key] = (int) $request[$key];
            }
            $result = $this->option('execute')
                ? $service->approve($request, (string) $this->option('operation'), (string) $this->option('reason'))
                : $service->review($request, (string) $this->option('operation'), (string) $this->option('reason'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->warn('No provider call, financial fulfillment or cutover was performed. A fresh complete cutover dry run is still required.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Disposition refused. Check schema, identity, fresh finance/reconcile access, immutable operation, reviewed dates and authenticated evidence.');

            return self::FAILURE;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}

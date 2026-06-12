<?php

namespace App\Console\Commands\Concerns;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentReconciliationPolicy;
use Illuminate\Database\Eloquent\Builder;

trait InteractsWithFibCheckoutReconciliation
{
    /**
     * @return array{candidates_scanned:int,skipped_applied:int,skipped_review_required:int,skipped_terminal:int,processed_unresolved:int,updated:int,failed:int}
     */
    protected function candidateSummary(Builder $query): array
    {
        $summary = [
            'candidates_scanned' => 0,
            'skipped_applied' => 0,
            'skipped_review_required' => 0,
            'skipped_terminal' => 0,
            'processed_unresolved' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        $query
            ->select([
                'id',
                'status',
                'internal_status',
                'fulfilled_at',
                'review_required_at',
                'provider_object_type',
                'payment_mode',
                'provider_status',
                'provider_payment_status',
                'provider_subscription_status',
                'active_until',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($payments) use (&$summary) {
                foreach ($payments as $payment) {
                    $summary['candidates_scanned']++;

                    match ($this->candidateBucket($payment)) {
                        'applied' => $summary['skipped_applied']++,
                        'review' => $summary['skipped_review_required']++,
                        'terminal' => $summary['skipped_terminal']++,
                        'unresolved' => $summary['processed_unresolved']++,
                        default => $summary['skipped_terminal']++,
                    };
                }
            });

        return $summary;
    }

    protected function candidateBucket(Payment $payment): string
    {
        return $this->checkoutReconciliationPolicy()->checkoutCandidateBucket($payment);
    }

    protected function renderSummary(array $summary, bool $dryRun): void
    {
        $this->line('Candidates scanned: '.number_format((int) ($summary['candidates_scanned'] ?? 0)));
        $this->line('Skipped applied: '.number_format((int) ($summary['skipped_applied'] ?? 0)));
        $this->line('Skipped review-required: '.number_format((int) ($summary['skipped_review_required'] ?? 0)));
        $this->line('Skipped terminal: '.number_format((int) ($summary['skipped_terminal'] ?? 0)));
        $this->line('Processed unresolved: '.number_format((int) ($summary['processed_unresolved'] ?? 0)));
        $this->line('Updated: '.number_format((int) ($summary['updated'] ?? 0)));
        $this->line('Failed: '.number_format((int) ($summary['failed'] ?? 0)));

        if ($dryRun) {
            $this->line('Mode: dry-run');
        }
    }

    protected function checkoutReconciliationPolicy(): PaymentReconciliationPolicy
    {
        return app(PaymentReconciliationPolicy::class);
    }
}

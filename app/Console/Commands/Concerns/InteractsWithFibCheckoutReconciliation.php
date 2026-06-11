<?php

namespace App\Console\Commands\Concerns;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

trait InteractsWithFibCheckoutReconciliation
{
    protected function unresolvedCheckoutQuery(Builder $query): Builder
    {
        return $query->where(function ($builder) {
            $builder
                ->whereIn('status', [
                    PaymentStatus::PENDING->value,
                    PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
                ])
                ->orWhere(function ($paidLike) {
                    $paidLike
                        ->where('status', PaymentStatus::PAID->value)
                        ->whereNull('fulfilled_at')
                        ->where(function ($statuses) {
                            $statuses
                                ->whereNull('internal_status')
                                ->orWhereIn('internal_status', [
                                    PaymentInternalStatus::PENDING->value,
                                    PaymentInternalStatus::AWAITING_CUSTOMER_ACTION->value,
                                    PaymentInternalStatus::PAID_PENDING_APPLICATION->value,
                                ]);
                        });
                });
        })->where(function ($builder) {
            $builder
                ->whereNull('internal_status')
                ->orWhereIn('internal_status', [
                    PaymentInternalStatus::PENDING->value,
                    PaymentInternalStatus::AWAITING_CUSTOMER_ACTION->value,
                    PaymentInternalStatus::PAID_PENDING_APPLICATION->value,
                ]);
        })->whereNull('review_required_at');
    }

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
            ->select(['id', 'status', 'internal_status', 'fulfilled_at', 'review_required_at'])
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
        $status = $this->rawPaymentStatus($payment);
        $internalStatus = $this->rawInternalStatus($payment);

        if ($payment->fulfilled_at !== null || in_array($internalStatus, ['applied', 'fulfilled'], true)) {
            return 'applied';
        }

        if ($internalStatus === PaymentInternalStatus::REQUIRES_REVIEW->value || $payment->review_required_at !== null) {
            return 'review';
        }

        if (in_array($status, [
            PaymentStatus::FAILED->value,
            PaymentStatus::CANCELED->value,
            PaymentStatus::EXPIRED->value,
            PaymentStatus::REFUND_REQUESTED->value,
            PaymentStatus::REFUNDED->value,
            'declined',
        ], true) || in_array($internalStatus, [
            PaymentInternalStatus::FAILED->value,
            PaymentInternalStatus::CANCELED->value,
            PaymentInternalStatus::EXPIRED->value,
            PaymentInternalStatus::REFUND_REQUESTED->value,
            PaymentInternalStatus::REFUNDED->value,
        ], true)) {
            return 'terminal';
        }

        if (in_array($status, [
            PaymentStatus::PENDING->value,
            PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
        ], true)) {
            return 'unresolved';
        }

        if ($status === PaymentStatus::PAID->value
            && $payment->fulfilled_at === null
            && in_array($internalStatus, [null, '', PaymentInternalStatus::PENDING->value, PaymentInternalStatus::AWAITING_CUSTOMER_ACTION->value, PaymentInternalStatus::PAID_PENDING_APPLICATION->value], true)) {
            return 'unresolved';
        }

        return 'terminal';
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

    protected function rawPaymentStatus(Payment $payment): ?string
    {
        $value = trim((string) $payment->getRawOriginal('status'));

        return $value !== '' ? strtolower($value) : null;
    }

    protected function rawInternalStatus(Payment $payment): ?string
    {
        $value = trim((string) $payment->getRawOriginal('internal_status'));

        return $value !== '' ? strtolower($value) : null;
    }
}

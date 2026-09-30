<?php

namespace App\Console\Commands;

use App\Domain\Payments\Models\Payment;
use App\Services\Billing\ProviderSubscriptionCancellation;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

class ReviewFibCancellation extends Command
{
    protected $signature = 'payments:review-fib-cancellation
        {payment : Exact local Payment ID}
        {--customer= : Expected customer ID}
        {--provider-subscription= : Expected FIB subscription ID}
        {--admin= : Active finance and reconcile Admin ID}
        {--operation= : Durable UUID; reuse only for the identical operation}
        {--reason= : Required audited reason for execution}
        {--dry-run : Persisted evidence only (default); no HTTP or writes}
        {--execute : GET-only provider verification and audited canonical confirmation}';

    protected $description = 'Review one paid FIB cancellation without cancellation POST, paid-term correction, refill or cutover.';

    public function handle(ProviderSubscriptionCancellation $cancellations): int
    {
        $guard = auth('admin');
        $previous = $guard->user();
        try {
            if (! ctype_digit((string) $this->option('admin')) || ! $guard->onceUsingId((int) $this->option('admin'))
                || ($this->option('execute') && $this->option('dry-run'))) {
                throw new \RuntimeException;
            }
            AdminAccess::authorize('admin.finance');
            AdminAccess::authorize('admin.reconcile');
            $payment = Payment::whereKey($this->argument('payment'))->where('customer_id', (int) $this->option('customer'))->firstOrFail();
            if (! $payment->fib_subscription_id || $payment->fib_subscription_id !== $this->option('provider-subscription')) {
                throw new \RuntimeException;
            }
            $result = $this->option('execute')
                ? $cancellations->reviewConfirmation((string) $this->option('operation'), (int) $payment->customer_id,
                    (int) $payment->id, $payment->fib_subscription_id, (string) $this->option('reason'))
                : ['payment_id' => $payment->id, 'cancellation_evidence' => $cancellations->confirmation($payment),
                    'coverage_disposition' => 'operator_review_required', 'cutover_authorized' => false];
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->warn('Paid dates and financial balances were not changed. Coverage disposition and a fresh cutover review remain required.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Cancellation review refused or unavailable. Check identity, capabilities and provider evidence; no coverage correction or cutover was authorized.');

            return self::FAILURE;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}

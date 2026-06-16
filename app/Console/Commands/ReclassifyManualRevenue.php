<?php

namespace App\Console\Commands;

use App\Domain\Payments\Models\Payment;
use App\Services\Payments\ManualRevenueReclassificationService;
use Illuminate\Console\Command;

class ReclassifyManualRevenue extends Command
{
    protected $signature = 'payments:reclassify-manual-revenue
        {payment : Local payment id}
        {--customer= : Expected customer id}
        {--as=manual_grant : Reclassify as manual_grant or internal_non_revenue}
        {--reason= : Operator reason for the reclassification}
        {--reverse-credits : Create ledger reversal rows for supported add-on credits}
        {--dry-run : Preview only (default unless --execute is provided)}
        {--execute : Perform the local reclassification without any provider calls}';

    protected $description = 'Reclassify an admin/manual payment as non-revenue without deleting accounting history. Dry-run by default.';

    public function __construct(
        protected ManualRevenueReclassificationService $reclassification,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        $payment = Payment::query()->find((int) $this->argument('payment'));

        if (! $payment instanceof Payment) {
            $this->error('The requested payment was not found.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('A --reason is required.');

            return self::FAILURE;
        }

        $preview = $this->reclassification->preview(
            $payment,
            max(0, (int) $this->option('customer')),
            (string) $this->option('as'),
            $reason,
            (bool) $this->option('reverse-credits'),
        );

        $this->newLine();
        $this->info('MANUAL REVENUE RECLASSIFICATION');
        $this->table(['Key', 'Value'], [
            ['payment_id', (string) $preview['payment_id']],
            ['customer_id', (string) $preview['customer_id']],
            ['as', (string) $preview['as']],
            ['reverse_credits', (bool) $preview['reverse_credits'] ? 'yes' : 'no'],
            ['order_count', (string) $preview['order_count']],
            ['would_exclude_revenue', (bool) $preview['would_exclude_revenue'] ? 'yes' : 'no'],
            ['would_detach_provider_subscription', (bool) $preview['would_detach_provider_subscription'] ? 'yes' : 'no'],
            ['mode', $dryRun ? 'dry-run' : 'execute'],
        ]);

        if (($preview['errors'] ?? []) !== []) {
            foreach ((array) $preview['errors'] as $error) {
                $this->error((string) $error);
            }

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->comment('Dry-run only. No payment, order, subscription, or ledger rows were changed.');

            return self::SUCCESS;
        }

        $result = $this->reclassification->execute(
            $payment,
            max(0, (int) $this->option('customer')),
            (string) $this->option('as'),
            $reason,
            (bool) $this->option('reverse-credits'),
        );

        $this->info('Manual revenue reclassification completed safely.');
        $this->line('Payment ID: '.(int) $result['payment_id']);
        $this->line('Revenue excluded: yes');
        $this->line('Billing source: '.(string) $result['billing_source']);
        $this->line('Reversed ledgers: '.number_format(count((array) ($result['reversed_ledger_ids'] ?? []))));

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Payments;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\CreditLedger;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use Illuminate\Support\Facades\DB;

class ManualRevenueReclassificationService
{
    public function __construct(
        protected PaymentEventRecorder $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        Payment $payment,
        int $expectedCustomerId,
        string $as,
        string $reason,
        bool $reverseCredits = false,
    ): array {
        $payment = $payment->fresh(['customer', 'serviceSubscriptions', 'storageSubscriptions']) ?? $payment;
        $orders = CreditOrder::query()
            ->where('payment_id', $payment->id)
            ->get();

        $errors = $this->validate($payment, $expectedCustomerId, $as, $reason, $reverseCredits, $orders);

        return [
            'payment_id' => (int) $payment->id,
            'customer_id' => (int) $payment->customer_id,
            'as' => $as,
            'reason' => $reason,
            'reverse_credits' => $reverseCredits,
            'order_ids' => $orders->pluck('id')->map(fn ($value) => (int) $value)->all(),
            'order_count' => $orders->count(),
            'service_subscription_ids' => $payment->serviceSubscriptions->pluck('id')->map(fn ($value) => (int) $value)->all(),
            'storage_subscription_ids' => $payment->storageSubscriptions->pluck('id')->map(fn ($value) => (int) $value)->all(),
            'would_exclude_revenue' => true,
            'would_detach_provider_subscription' => $payment->serviceSubscriptions->isNotEmpty(),
            'errors' => $errors,
            'can_execute' => $errors === [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        Payment $payment,
        int $expectedCustomerId,
        string $as,
        string $reason,
        bool $reverseCredits = false,
    ): array {
        $preview = $this->preview($payment, $expectedCustomerId, $as, $reason, $reverseCredits);

        if (! ($preview['can_execute'] ?? false)) {
            throw new \RuntimeException(implode(' ', (array) ($preview['errors'] ?? [])));
        }

        return DB::transaction(function () use ($payment, $as, $reason, $reverseCredits) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->with(['serviceSubscriptions', 'storageSubscriptions'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $orders = CreditOrder::query()
                ->where('payment_id', $lockedPayment->id)
                ->lockForUpdate()
                ->get();

            $reclassificationCode = $as === 'manual_grant' ? 'admin_manual_grant' : 'internal_non_revenue';

            $paymentMeta = array_merge((array) ($lockedPayment->meta ?? []), [
                'billing_source' => $reclassificationCode,
                'revenue_record' => false,
                'revenue_excluded' => true,
                'manual_revenue_reclassification' => [
                    'as' => $as,
                    'reason' => $reason,
                    'reverse_credits' => $reverseCredits,
                    'reclassified_at' => now()->toIso8601String(),
                ],
            ]);

            $lockedPayment->forceFill([
                'meta' => $paymentMeta,
            ])->save();

            foreach ($orders as $order) {
                $orderMeta = array_merge((array) ($order->meta ?? []), [
                    'billing_source' => $reclassificationCode,
                    'revenue_record' => false,
                    'revenue_excluded' => true,
                    'manual_revenue_reclassification' => [
                        'as' => $as,
                        'reason' => $reason,
                        'reverse_credits' => $reverseCredits,
                        'reclassified_at' => now()->toIso8601String(),
                    ],
                ]);

                $order->forceFill([
                    'meta' => $orderMeta,
                ])->save();
            }

            foreach ($lockedPayment->serviceSubscriptions as $subscription) {
                $subscriptionMeta = array_merge((array) ($subscription->meta ?? []), [
                    'billing_source' => 'admin_manual_grant',
                    'revenue_record' => false,
                    'reclassified_from_payment_id' => (int) $lockedPayment->id,
                    'reason' => $reason,
                    'reclassified_at' => now()->toIso8601String(),
                ]);

                $subscription->forceFill([
                    'payment_id' => null,
                    'source' => 'admin_manual_grant',
                    'provider_ref' => null,
                    'auto_renew' => false,
                    'renewal_strategy' => 'manual_renewal',
                    'meta' => $subscriptionMeta,
                ])->save();
            }

            foreach ($lockedPayment->storageSubscriptions as $subscription) {
                $subscriptionMeta = array_merge((array) ($subscription->meta ?? []), [
                    'billing_source' => 'internal_non_revenue',
                    'revenue_record' => false,
                    'reclassified_from_payment_id' => (int) $lockedPayment->id,
                    'reason' => $reason,
                    'reclassified_at' => now()->toIso8601String(),
                ]);

                $subscription->forceFill([
                    'payment_id' => null,
                    'source' => 'admin_manual',
                    'provider_ref' => null,
                    'auto_renew' => false,
                    'renewal_strategy' => 'manual_renewal',
                    'meta' => $subscriptionMeta,
                ])->save();
            }

            $reversedLedgerIds = [];

            if ($reverseCredits) {
                foreach ($orders as $order) {
                    foreach ($this->reverseAddonCreditsForOrder($lockedPayment, $order, $reason) as $ledgerId) {
                        $reversedLedgerIds[] = $ledgerId;
                    }
                }
            }

            $this->events->record($lockedPayment, [
                'event_type' => 'manual_revenue_reclassified',
                'source' => 'payments:reclassify-manual-revenue',
                'event_key' => 'manual-revenue-reclassified:'.$lockedPayment->id.':'.$reclassificationCode.':'.($reverseCredits ? 'reversed' : 'kept'),
                'before_status' => $lockedPayment->status?->value,
                'after_status' => $lockedPayment->status?->value,
                'meta' => [
                    'billing_source' => $reclassificationCode,
                    'revenue_record' => false,
                    'revenue_excluded' => true,
                    'reason' => $reason,
                    'reverse_credits' => $reverseCredits,
                    'reversed_ledger_ids' => $reversedLedgerIds,
                ],
            ]);

            return [
                'payment_id' => (int) $lockedPayment->id,
                'billing_source' => $reclassificationCode,
                'revenue_excluded' => true,
                'reverse_credits' => $reverseCredits,
                'reversed_ledger_ids' => $reversedLedgerIds,
            ];
        }, 3);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CreditOrder>  $orders
     * @return list<string>
     */
    protected function validate(
        Payment $payment,
        int $expectedCustomerId,
        string $as,
        string $reason,
        bool $reverseCredits,
        $orders,
    ): array {
        $errors = [];

        if ($expectedCustomerId > 0 && (int) $payment->customer_id !== $expectedCustomerId) {
            $errors[] = 'The payment does not belong to the expected customer.';
        }

        if (! in_array($as, ['manual_grant', 'internal_non_revenue'], true)) {
            $errors[] = 'Unsupported reclassification target.';
        }

        if (trim($reason) === '') {
            $errors[] = 'A reason is required.';
        }

        if (! $this->looksSafeForManualReclassification($payment, $orders)) {
            $errors[] = 'This payment does not look like a safe admin/manual/internal record to reclassify.';
        }

        if ($reverseCredits && $orders->contains(fn (CreditOrder $order) => $order->source_type !== 'credit_product')) {
            $errors[] = 'Credit reversal is only supported for add-on credit orders in this command.';
        }

        return $errors;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CreditOrder>  $orders
     */
    protected function looksSafeForManualReclassification(Payment $payment, $orders): bool
    {
        if ((bool) data_get($payment->meta, 'admin_adjustment', false)) {
            return true;
        }

        if (str_starts_with((string) data_get($payment->meta, 'ui', ''), 'admin.')) {
            return true;
        }

        if (in_array((string) data_get($payment->meta, 'billing_source', ''), [
            'admin_manual_grant',
            'internal_non_revenue',
        ], true)) {
            return true;
        }

        if ($orders->contains(function (CreditOrder $order): bool {
            return in_array((string) ($order->payment_method ?? ''), ['admin_manual', 'fake'], true)
                || str_starts_with((string) ($order->provider_ref ?? ''), 'ADMIN-')
                || str_starts_with((string) ($order->provider_ref ?? ''), 'FAKE-');
        })) {
            return true;
        }

        return $payment->serviceSubscriptions()
            ->where('source', 'admin_manual_grant')
            ->exists();
    }

    /**
     * @return list<int>
     */
    protected function reverseAddonCreditsForOrder(Payment $payment, CreditOrder $order, string $reason): array
    {
        if ($order->source_type !== 'credit_product' || (int) $order->credits_amount <= 0) {
            return [];
        }

        /** @var CreditWallet $wallet */
        $wallet = CreditWallet::query()
            ->where('customer_id', $payment->customer_id)
            ->where('wallet_type', CreditWallet::TYPE_APP)
            ->lockForUpdate()
            ->firstOrFail();

        $credits = (int) $order->credits_amount;
        $addonBefore = (int) ($wallet->addon_balance_credits ?? 0);
        $balanceBefore = (int) ($wallet->balance_credits ?? 0);

        if ($addonBefore < $credits) {
            throw new \RuntimeException('Cannot reverse add-on credits because the customer no longer has enough add-on balance.');
        }

        $wallet->addon_balance_credits = $addonBefore - $credits;
        $wallet->syncCombinedBalance();
        $wallet->save();

        $ledger = CreditLedger::create([
            'customer_id' => (int) $payment->customer_id,
            'wallet_type' => CreditWallet::TYPE_APP,
            'type' => 'manual_revenue_reversal',
            'source_type' => 'manual_revenue_reclassification',
            'source_id' => (string) $payment->id,
            'direction' => 'debit',
            'amount' => $credits,
            'bucket' => 'addon',
            'credits_delta' => -$credits,
            'balance_before' => $balanceBefore,
            'balance_after' => (int) $wallet->balance_credits,
            'subscription_balance_after' => (int) ($wallet->subscription_balance_credits ?? 0),
            'addon_balance_after' => (int) ($wallet->addon_balance_credits ?? 0),
            'related_type' => CreditOrder::class,
            'related_id' => (string) $order->id,
            'reference_code' => 'REV-ORDER-'.$order->id.'-'.now()->format('YmdHis'),
            'meta' => [
                'payment_id' => (int) $payment->id,
                'order_id' => (int) $order->id,
                'reason' => $reason,
                'reclassified_at' => now()->toIso8601String(),
            ],
        ]);

        $orderMeta = array_merge((array) ($order->meta ?? []), [
            'credit_reversal' => [
                'reversed' => true,
                'ledger_id' => (int) $ledger->id,
                'reversed_at' => now()->toIso8601String(),
                'reason' => $reason,
            ],
        ]);

        $order->forceFill(['meta' => $orderMeta])->save();

        return [(int) $ledger->id];
    }
}

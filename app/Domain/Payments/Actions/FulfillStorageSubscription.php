<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\RecurringPaymentHandler;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Services\Billing\PlanSwitcher;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use Illuminate\Support\Facades\DB;

class FulfillStorageSubscription implements RecurringPaymentHandler
{
    public function __construct(
        protected PaymentEventRecorder $events,
        protected PlanSwitcher $switcher,
    ) {
    }

    public function supports(PurchaseType $purchaseType): bool
    {
        return $purchaseType === PurchaseType::STORAGE_SUBSCRIPTION;
    }

    public function handle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->with(['customer.profile', 'purchasable'])->findOrFail($payment->id);

            if ($locked->fulfilled_at !== null || $locked->status !== PaymentStatus::PAID) {
                return;
            }

            $customer = $locked->customer;
            $plan = $locked->purchasable;
            $snapshot = $locked->snapshot();
            $feeQuote = $locked->feeQuote();
            $subscription = $this->switcher->switchStoragePlan($customer, (int) $locked->purchasable_id, [
                'provider' => $locked->provider->value,
                'provider_ref' => $locked->fib_payment_id ?: $locked->local_reference,
                'payment_method' => $locked->provider->value,
                'payment_id' => $locked->id,
                'merchant_transaction_id' => $locked->local_reference,
                'gross_amount_iqd' => (int) ($feeQuote['gross_amount_iqd'] ?? round((float) $locked->amount)),
                'surcharge_amount_iqd' => (int) ($feeQuote['surcharge_amount_iqd'] ?? 0),
                'provider_fee_amount_iqd' => (int) ($feeQuote['provider_fee_amount_iqd'] ?? 0),
                'net_amount_iqd' => (int) ($feeQuote['net_amount_iqd'] ?? data_get($snapshot, 'amount_iqd', round((float) $locked->amount))),
                'fee_breakdown' => data_get($feeQuote, 'fee_breakdown'),
                'paid_at' => $locked->paid_at ?? now(),
                'renewal_strategy' => 'manual_renewal',
            ]);

            $locked->forceFill([
                'fulfilled_at' => now(),
                'meta' => array_merge((array) $locked->meta, [
                    'fulfilled_storage_subscription_id' => $subscription->id,
                ]),
            ])->save();

            TelegramPaymentNotifier::send(
                $customer->fresh(['profile']),
                'Storage Plan',
                (string) ($snapshot['name'] ?? $plan?->name ?? 'Storage Plan'),
                [
                    'Plan Code' => strtoupper((string) ($snapshot['code'] ?? $plan?->code ?? '')),
                    'Storage Quota (MB)' => number_format((int) ($snapshot['quota_mb'] ?? 0)),
                    'Amount (IQD)' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'Estimated Local Price' => (string) data_get($snapshot, 'display.display_label', ''),
                    'Provider' => strtoupper($locked->provider->value),
                    'Reference' => (string) ($locked->fib_payment_id ?: $locked->local_reference),
                ],
                'FIB storage fulfillment'
            );

            CustomerEmailNotifier::sendStorageThankYou(
                $customer,
                [
                    'plan_name' => (string) ($snapshot['name'] ?? $plan?->name ?? 'Storage Plan'),
                    'quota_mb' => (int) ($snapshot['quota_mb'] ?? 0),
                    'amount_label' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'activated_on' => now()->format('F d, Y'),
                ],
                'FIB storage fulfillment'
            );

            $this->events->record($locked, [
                'event_type' => 'payment_fulfilled',
                'source' => 'fulfillment_listener',
                'before_status' => $locked->status->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'storage_subscription_id' => $subscription->id,
                ],
            ]);
        }, 3);
    }
}

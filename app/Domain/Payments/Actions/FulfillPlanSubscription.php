<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Contracts\RecurringPaymentHandler;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Services\Billing\PlanSwitcher;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use Illuminate\Support\Facades\DB;

class FulfillPlanSubscription implements RecurringPaymentHandler
{
    public function __construct(
        protected PaymentEventRecorder $events,
        protected PlanSwitcher $switcher,
    ) {
    }

    public function supports(PurchaseType $purchaseType): bool
    {
        return $purchaseType === PurchaseType::PLAN_SUBSCRIPTION;
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
            $billingCycle = (string) ($snapshot['billing_cycle'] ?? 'monthly');
            $subscription = $this->switcher->switchServicePlan($customer, (int) $locked->purchasable_id, [
                'provider' => $locked->provider->value,
                'provider_ref' => $locked->providerReference(),
                'payment_method' => $locked->provider->value,
                'payment_id' => $locked->id,
                'merchant_transaction_id' => $locked->local_reference,
                'gross_amount_iqd' => (int) ($feeQuote['gross_amount_iqd'] ?? round((float) $locked->amount)),
                'surcharge_amount_iqd' => (int) ($feeQuote['surcharge_amount_iqd'] ?? 0),
                'provider_fee_amount_iqd' => (int) ($feeQuote['provider_fee_amount_iqd'] ?? 0),
                'net_amount_iqd' => (int) ($feeQuote['net_amount_iqd'] ?? data_get($snapshot, 'amount_iqd', round((float) $locked->amount))),
                'fee_breakdown' => data_get($feeQuote, 'fee_breakdown'),
                'paid_at' => $locked->paid_at ?? now(),
                'billing_cycle' => $billingCycle,
                'renewal_strategy' => $locked->isProviderSubscriptionObject()
                    ? PaymentRecurringStrategy::PROVIDER_SCHEDULE->value
                    : PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                'active_until' => $locked->active_until,
            ]);

            $locked->forceFill([
                'fulfilled_at' => now(),
                'meta' => array_merge((array) $locked->meta, [
                    'fulfilled_subscription_id' => $subscription->id,
                ]),
            ])->save();

            TelegramPaymentNotifier::send(
                $customer->fresh(['profile']),
                'Subscription Plan',
                (string) ($snapshot['name'] ?? $plan?->name ?? 'Plan'),
                [
                    'Billing Cycle' => ucfirst($billingCycle),
                    'Plan Code' => strtoupper((string) ($snapshot['code'] ?? $plan?->code ?? '')),
                    'Monthly Credits' => number_format((int) ($snapshot['monthly_credits'] ?? 0)),
                    'Price (IQD)' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'Estimated Local Price' => (string) data_get($snapshot, 'display.display_label', ''),
                    'Provider' => strtoupper($locked->provider->value),
                    'Reference' => $locked->providerReference(),
                ],
                'FIB plan fulfillment'
            );

            CustomerEmailNotifier::sendSubscriptionThankYou(
                $customer,
                [
                    'plan_name' => (string) ($snapshot['name'] ?? $plan?->name ?? 'Plan'),
                    'billing_cycle' => ucfirst($billingCycle),
                    'monthly_credits' => (int) ($snapshot['monthly_credits'] ?? 0),
                    'amount_label' => (string) data_get($snapshot, 'display.iqd_label', ''),
                    'activated_on' => now()->format('F d, Y'),
                ],
                'FIB plan fulfillment'
            );

            $this->events->record($locked, [
                'event_type' => 'payment_fulfilled',
                'source' => 'fulfillment_listener',
                'before_status' => $locked->status->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'subscription_id' => $subscription->id,
                ],
            ]);
        }, 3);
    }
}

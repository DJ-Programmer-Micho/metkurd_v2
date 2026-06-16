<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurposeType;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Facades\DB;

class PaymentFulfillmentService
{
    public function __construct(
        protected AddonPurchaseService $addonPurchaseService,
    ) {}

    /**
     * @return array{order:?CreditOrder,subscription:mixed}
     */
    public function fulfill(PaymentIntent $intent): array
    {
        if ($intent->fulfilled_at !== null) {
            return [
                'order' => $intent->creditOrders()->latest('id')->first(),
                'subscription' => null,
            ];
        }

        return DB::transaction(function () use ($intent) {
            /** @var PaymentIntent $intent */
            $intent = PaymentIntent::query()
                ->lockForUpdate()
                ->with('customer.profile')
                ->findOrFail($intent->id);

            if ($intent->fulfilled_at !== null) {
                return [
                    'order' => $intent->creditOrders()->latest('id')->first(),
                    'subscription' => null,
                ];
            }

            /** @var Customer $customer */
            $customer = $intent->customer;
            $purposeType = PaymentPurposeType::from((string) $intent->purpose_type);
            $meta = [
                'provider' => $intent->provider,
                'provider_ref' => $intent->provider_payment_id ?: $intent->provider_transaction_id ?: $intent->merchant_transaction_id,
                'payment_method' => $intent->payment_method,
                'payment_intent_id' => $intent->id,
                'merchant_transaction_id' => $intent->merchant_transaction_id,
                'provider_transaction_id' => $intent->provider_transaction_id,
                'customer_payment_method_id' => $intent->customer_payment_method_id,
                'renewal_strategy' => $intent->recurring_strategy,
                'gross_amount_iqd' => (int) round((float) $intent->gross_amount_iqd),
                'surcharge_amount_iqd' => (int) round((float) $intent->surcharge_amount_iqd),
                'provider_fee_amount_iqd' => (int) round((float) $intent->provider_fee_amount_iqd),
                'net_amount_iqd' => (int) round((float) ($intent->net_amount_iqd ?? 0)),
                'paid_at' => $intent->paid_at ?? now(),
                'fee_breakdown' => $intent->meta['fee_breakdown'] ?? null,
            ];

            $order = null;
            $subscription = null;

            if ($purposeType === PaymentPurposeType::SERVICE_PLAN) {
                $subscription = app(PlanSwitcher::class)->switchServicePlan($customer, (int) $intent->purpose_id, [
                    'ui' => 'subscription-plan-page',
                    'billing_cycle' => $intent->billing_interval ?: 'monthly',
                    ...$meta,
                ]);
                $order = CreditOrder::query()->where('payment_intent_id', $intent->id)->latest('id')->first();
            } elseif ($purposeType === PaymentPurposeType::STORAGE_PLAN) {
                $subscription = app(PlanSwitcher::class)->switchStoragePlan($customer, (int) $intent->purpose_id, [
                    'ui' => 'storage-plan-page',
                    ...$meta,
                ]);
                $order = CreditOrder::query()->where('payment_intent_id', $intent->id)->latest('id')->first();
            } else {
                $order = $this->addonPurchaseService->purchase($customer, (int) $intent->purpose_id, [
                    'ui' => 'addon-credits-page',
                    ...$meta,
                ]);
            }

            $intent->forceFill([
                'fulfilled_at' => now(),
            ])->save();

            return [
                'order' => $order,
                'subscription' => $subscription,
            ];
        }, 3);
    }
}

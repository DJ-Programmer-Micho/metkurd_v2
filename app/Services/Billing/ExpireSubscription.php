<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ExpireSubscription
{
    public function handle(CustomerServiceSubscription|CustomerStorageSubscription $subscription, ?CarbonInterface $threshold = null): bool
    {
        return DB::transaction(function () use ($subscription, $threshold) {
            Customer::query()->whereKey($subscription->customer_id)->lockForUpdate()->firstOrFail();
            $payment = null;
            if ($subscription->payment_id) {
                $payment = Payment::query()->whereKey($subscription->payment_id)->lockForUpdate()->firstOrFail();
            }
            $subscription = $subscription->newQuery()->lockForUpdate()->findOrFail($subscription->id);
            $subscription->setRelation('payment', $payment);
            $policy = app(SubscriptionCyclePolicy::class);
            $end = $policy->boundary($subscription);
            if (! $policy->isCurrent($subscription) || ! $end || $end->gt($threshold ?? now())) {
                return false;
            }
            $service = $subscription instanceof CustomerServiceSubscription;
            $billing = app(CustomerBillingStateService::class);
            $free = $service ? $billing->defaultServicePlan() : $billing->defaultStoragePlan();
            $planKey = $service ? 'service_plan_id' : 'storage_plan_id';
            if ((int) $subscription->{$planKey} === (int) $free->id) {
                return false;
            }
            if ($subscription->source === ServiceAgreementLifecycle::SOURCE) {
                if ($end->isFuture()) {
                    return false;
                }
                app(ServiceAgreementLifecycle::class)->expireCredits($subscription);
            }
            if ($payment?->isProviderSubscriptionObject()) {
                app(ProviderSubscriptionCancellation::class)->request($payment, 'failed_renewal');
                $subscription = $subscription->fresh();
            }
            $subscription->forceFill([
                'status' => 'ended', 'auto_renew' => false, 'ends_at' => $end,
                'canceled_at' => $subscription->canceled_at ?? now(),
                'meta' => array_merge((array) $subscription->meta, [
                    'cancel_source' => data_get($subscription->meta, 'cancel_source', $subscription->source === ServiceAgreementLifecycle::SOURCE ? 'agreement_term_end' : 'renewal_failed'),
                    'expired_at' => now()->toIso8601String(),
                    'renewal_payment_missing' => $payment?->isProviderSubscriptionObject()
                        && data_get($subscription->meta, 'provider_cancellation.reason_code') === 'failed_renewal'
                        && $policy->verifiedCollection($payment),
                ]),
            ])->save();
            $attributes = [
                'customer_id' => $subscription->customer_id, $planKey => $free->id,
                'status' => 'active', 'source' => 'system', 'starts_at' => now(),
                'cycle_started_on' => now()->toDateString(),
                'cycle_ends_on' => now()->addMonthNoOverflow()->subDay()->toDateString(),
                'next_renewal_on' => now()->addMonthNoOverflow()->toDateString(),
                'auto_renew' => false, 'renewal_strategy' => 'manual_renewal',
                'meta' => ['activated_by' => $subscription->source === ServiceAgreementLifecycle::SOURCE ? 'billing:process-service-agreements' : 'subscriptions:reconcile',
                    'activated_reason' => $subscription->source === ServiceAgreementLifecycle::SOURCE ? 'agreement_term_end' : 'renewal_failed_downgrade',
                    'from_subscription_id' => $subscription->id],
            ];
            if ($service) {
                $attributes['previous_service_plan_id'] = $subscription->service_plan_id;
            }
            $subscription->newQuery()->create($attributes);

            return true;
        }, 3);
    }
}

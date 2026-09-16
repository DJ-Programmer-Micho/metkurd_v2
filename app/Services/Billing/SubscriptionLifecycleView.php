<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;

/** Owned local reads only; display never reconciles or calls FIB. */
class SubscriptionLifecycleView
{
    public function customer(Customer $customer, string $kind = 'service'): array
    {
        $class = $kind === 'storage' ? CustomerStorageSubscription::class : CustomerServiceSubscription::class;
        $planClass = $kind === 'storage' ? StoragePlan::class : ServicePlan::class;
        $relation = $kind === 'storage' ? 'storagePlan' : 'servicePlan';
        $billing = app(CustomerBillingStateService::class);
        $state = $kind === 'storage' ? $billing->storageQuotaState($customer) : $billing->servicePlanState($customer);
        $current = $state['subscription'];
        $recent = $class::where('customer_id', $customer->id)->with($relation)->latest('id')->limit(10)->get();
        $pending = Payment::currentBillingPeriod()->where('customer_id', $customer->id)->where('purchasable_type', $planClass)
            ->whereNull('fulfilled_at')->whereIn('status', ['pending', 'awaiting_customer_action'])->latest('id')->limit(10)->get()
            ->first(fn ($p) => app(PaymentCheckoutState::class)->blocks($p));
        $oldPending = $class::where('customer_id', $customer->id)->with($relation)
            ->where('meta->provider_cancellation->reason_code', 'plan_switch')
            ->where('meta->provider_cancellation->provider_cancel_pending', true)->latest('id')->first();
        if ($boundary = app(BillingReportingBoundary::class)->current()) {
            $recent = $recent->filter(fn ($s) => $s->created_at && $s->created_at->gte($boundary['starts_at']));
            if ($oldPending && ! $oldPending->payment?->isCurrentBillingPeriod()) {
                $oldPending = null;
            }
        }
        $previous = $recent->first(fn ($s) => $s->status === 'ended' && ! data_get($s->meta, 'superseded_at'));
        $context = (array) data_get($current?->meta, 'provider_cancellation', []);

        return ['renewal' => $context['state'] ?? (data_get($current?->meta, 'provider_status') === 'REJECTED'
            ? 'issue' : ($current?->auto_renew ? 'renewing' : 'off')),
            'until' => \App\Domain\Payments\Support\FibSubscriptionTimestamp::parse($context['effective_access_until'] ?? null)
                ?? ($state['period_ends_at'] ?? $current?->ends_at),
            'old_pending' => $oldPending?->{$relation}?->name,
            'previous_ended' => $previous?->{$relation}?->name,
            'changing_to' => $pending ? data_get($pending->purchase_snapshot, 'name') : null];
    }
}

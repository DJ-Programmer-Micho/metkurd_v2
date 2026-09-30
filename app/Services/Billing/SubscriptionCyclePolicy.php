<?php

namespace App\Services\Billing;

use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\FibStatusEvidence;
use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use Carbon\CarbonInterface;

class SubscriptionCyclePolicy
{
    public function isCurrent(CustomerServiceSubscription|CustomerStorageSubscription $subscription): bool
    {
        return app(BillingSubscriptionAuthority::class)->apply($subscription->newQuery()->whereKey($subscription->id), lifecycle: true)->exists()
            && ! data_get($subscription->meta, 'superseded_at')
            && ! $subscription->newQuery()->where('customer_id', $subscription->customer_id)
                ->where('id', '>', $subscription->id)->orderBy('id')->lockForUpdate()->first(['id']);
    }

    /** No provider call: only previously authenticated, matched GET evidence. */
    public function verifiedCollection(?Payment $payment): bool
    {
        if (! $payment || ! $payment->isProviderSubscriptionObject() || ! $payment->isFulfilled()
            || $payment->status !== PaymentStatus::PAID || ! $payment->last_payment_at || ! $payment->active_until) {
            return false;
        }
        $verified = data_get($payment->meta, 'verified_subscription_collection', []);
        $last = FibSubscriptionTimestamp::parse(data_get($verified, 'last_payment_at'));
        $end = FibSubscriptionTimestamp::parse(data_get($verified, 'paid_through'));
        if (data_get($verified, 'provider_object_id') === $payment->fib_subscription_id
            && $last && $end && $last->timestamp === $payment->last_payment_at->timestamp
            && $end->timestamp === $payment->active_until->timestamp && $end->gt($last)) {
            return true;
        }
        $status = FibSubscriptionStatusData::fromArray((array) $payment->status_response);

        return app(FibStatusEvidence::class)->rejection($payment, $status) === null
            && $status->lastPaymentAt && $status->activeUntil
            && $status->lastPaymentAt->timestamp === $payment->last_payment_at->timestamp
            && $status->activeUntil->timestamp === $payment->active_until->timestamp
            && $payment->active_until->gt($payment->last_payment_at);
    }

    public function boundary(CustomerServiceSubscription|CustomerStorageSubscription $subscription): ?CarbonInterface
    {
        if ($retained = app(ProviderCoverageDispositions::class)->retainedFor($subscription)) {
            return FibSubscriptionTimestamp::parse($retained->coverage_end);
        }
        $payment = $subscription->payment;
        // Explicit paid-through evidence wins over date-only scheduling hints.
        $end = $payment?->active_until;
        foreach (['verified_paid_through', 'period_ends_at', 'provider_active_until'] as $key) {
            $candidate = FibSubscriptionTimestamp::parse(data_get($subscription->meta, $key));
            if ($candidate && (! $end || $candidate->gt($end))) {
                $end = $candidate->setTimezone(config('app.timezone'));
            }
        }
        if ($subscription->ends_at && (! $end || (! $subscription->auto_renew && $subscription->ends_at->lt($end)))) {
            $end = $subscription->ends_at;
        }
        if ($payment?->isProviderSubscriptionObject()) {
            return $end; // Never manufacture missing provider coverage from an anniversary.
        }

        return $end ?? $subscription->cycle_ends_on?->copy()->endOfDay()
            ?? $subscription->next_renewal_on?->copy()->endOfDay();
    }

    public function calendarAllocation(CustomerServiceSubscription $subscription, CarbonInterface $dueAt): ?array
    {
        if (! $this->isCurrent($subscription) || $subscription->status !== 'active') {
            return null;
        }
        $payment = $subscription->payment;
        $plan = $subscription->servicePlan;
        if ($payment && ((int) $payment->customer_id !== (int) $subscription->customer_id
            || $payment->purchasable_type !== \App\Models\ServicePlan::class
            || (int) $payment->purchasable_id !== (int) $subscription->service_plan_id)) {
            return null;
        }
        if ($plan?->is_free) {
            return ['key' => 'calendar:'.$dueAt->format('Y-m'), 'type' => 'free_monthly'];
        }
        $end = $this->boundary($subscription);
        if (! $end || ! $end->isFuture() || $dueAt->gte($end)) {
            return null;
        }
        $yearly = data_get($subscription->meta, 'billing_cycle') === 'yearly';
        if ($payment?->isProviderSubscriptionObject()) {
            if (! $yearly || ! $this->verifiedCollection($payment)) {
                return null;
            }
            $start = $payment->last_payment_at;
            $months = ($dueAt->year - $start->year) * 12 + $dueAt->month - $start->month;
            if ($months < 1 || $months > 11 || now()->lt($start->copy()->addMonthsNoOverflow($months))) {
                return null;
            }

            return ['key' => $payment->providerRecurringCycleKey().':month:'.$months, 'type' => 'annual_monthly'];
        }
        if ($yearly && data_get($payment?->purchase_snapshot, 'billing_cycle') === 'yearly'
            && $payment?->status === PaymentStatus::PAID && $payment->isFulfilled()) {
            $start = $subscription->starts_at;
            $months = $start ? ($dueAt->year - $start->year) * 12 + $dueAt->month - $start->month : 0;
            if ($months < 1 || $months > 11 || now()->lt($start->copy()->addMonthsNoOverflow($months))) {
                return null;
            }

            return ['key' => 'prepaid:'.$payment->id.':month:'.$months, 'type' => 'annual_monthly'];
        }
        // Explicit local grants retain their existing non-provider calendar policy.
        if (! $payment && in_array($subscription->source, ['admin_manual_grant', 'admin_manual', 'admin', 'manual'], true)) {
            return ['key' => 'calendar:'.$dueAt->format('Y-m'), 'type' => 'manual_monthly'];
        }

        return null;
    }
}

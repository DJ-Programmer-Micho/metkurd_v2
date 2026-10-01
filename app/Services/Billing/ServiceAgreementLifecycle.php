<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\ServicePlanAgreement;
use App\Models\SubscriptionCreditAllocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ServiceAgreementLifecycle
{
    public const SOURCE = 'admin_cash_agreement';

    public function underCheckoutLock(int $customerId, callable $work): mixed
    {
        $lock = Cache::lock('customer-purchase:'.$customerId.':service', 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['agreement' => __('purchase_v2.busy')]);
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    public function hasReservedTerm(Customer $customer): bool
    {
        return Schema::hasTable('service_plan_agreements') && ServicePlanAgreement::where('customer_id', $customer->id)
            ->whereIn('status', ['scheduled', 'active', 'requires_review'])->where('ends_at', '>', now())->exists();
    }

    public function process(int $id): string
    {
        $candidate = ServicePlanAgreement::findOrFail($id);

        return $this->underCheckoutLock($candidate->customer_id, fn () => DB::transaction(function () use ($id, $candidate) {
            $customer = Customer::whereKey($candidate->customer_id)->lockForUpdate()->firstOrFail();
            $agreement = ServicePlanAgreement::whereKey($id)->lockForUpdate()->firstOrFail();

            return $this->processLocked($agreement, $customer);
        }, 3));
    }

    /** Caller holds the checkout lock, customer row lock and transaction. */
    public function processLocked(ServicePlanAgreement $agreement, Customer $customer): string
    {
        $boundary = app(BillingReportingBoundary::class)->fullReset();
        if ($boundary && $agreement->id <= $boundary['service_plan_agreements']) {
            return 'ended'; // Retired agreements cannot refill carried credits, including queued stale models.
        }
        if ($agreement->status === 'ended' || $agreement->starts_at->isFuture()) {
            return $agreement->status;
        }
        $subscription = $agreement->subscription_id
            ? CustomerServiceSubscription::whereKey($agreement->subscription_id)->lockForUpdate()->firstOrFail() : null;
        if ($subscription && (! app(SubscriptionCyclePolicy::class)->isCurrent($subscription) || $subscription->status !== 'active')) {
            $agreement->update(['status' => 'ended', 'review_code' => 'superseded']);

            return 'ended'; // Another authorized plan change owns the wallets now.
        }
        if (now()->gte($agreement->ends_at)) {
            if ($subscription) {
                app(ExpireSubscription::class)->handle($subscription);
            }
            $agreement->update(['status' => 'ended']);

            return 'ended';
        }
        if (! $subscription) {
            $plan = ServicePlan::whereKey($agreement->service_plan_id)->lockForUpdate()->firstOrFail();
            if (! $plan->is_active || $this->activationBlocked($customer)) {
                $agreement->update(['status' => 'requires_review', 'review_code' => 'activation_conflict']);

                return 'requires_review';
            }
            $previous = CustomerServiceSubscription::where('customer_id', $customer->id)->latest('id')->lockForUpdate()->first();
            // Future subscriptions belong to another scheduling mechanism; never supersede them.
            if ($previous?->starts_at?->isFuture()) {
                $agreement->update(['status' => 'requires_review', 'review_code' => 'activation_conflict']);

                return 'requires_review';
            }
            if ($previous) {
                $previous->update(['status' => 'ended', 'auto_renew' => false, 'ends_at' => $previous->ends_at && $previous->ends_at->isPast() ? $previous->ends_at : now(),
                    'meta' => array_merge((array) $previous->meta, ['superseded_at' => now()->toIso8601String(), 'superseded_by_agreement_id' => $agreement->id])]);
            }
            $subscription = CustomerServiceSubscription::create([
                'customer_id' => $customer->id, 'service_plan_id' => $plan->id, 'previous_service_plan_id' => $previous?->service_plan_id,
                'payment_id' => null, 'status' => 'active', 'source' => self::SOURCE,
                'starts_at' => $agreement->starts_at, 'ends_at' => $agreement->ends_at, 'auto_renew' => false,
                'renewal_strategy' => 'manual_renewal', 'cycle_started_on' => $agreement->starts_at->toDateString(),
                'cycle_ends_on' => $agreement->ends_at->copy()->subDay()->toDateString(), 'next_renewal_on' => null,
                'meta' => ['agreement_id' => $agreement->id, 'billing_source' => self::SOURCE, 'billing_cycle' => 'custom',
                    'collection_management' => 'external', 'payment_method' => 'cash', 'admin_id' => $agreement->admin_id,
                    'operation_id' => $agreement->operation_id, 'reason' => $agreement->reason,
                    'period_ends_at' => $agreement->ends_at->toIso8601String()],
            ]);
            $agreement->update(['subscription_id' => $subscription->id, 'status' => 'active', 'review_code' => null]);
            $this->audit($agreement, 'agreement.activated');
        }
        // Only the current anniversary interval is allocated after downtime: never stack missed months.
        $months = (now()->year - $agreement->starts_at->year) * 12 + now()->month - $agreement->starts_at->month;
        if (now()->lt($agreement->starts_at->copy()->addMonthsNoOverflow($months))) {
            $months--;
        }
        $cycleStart = $agreement->starts_at->copy()->addMonthsNoOverflow($months);
        $cycleEnd = $agreement->starts_at->copy()->addMonthsNoOverflow($months + 1)->min($agreement->ends_at);
        $this->allocate($agreement, $subscription, 'month:'.$months, $cycleStart, $cycleEnd,
            $agreement->app_monthly_credits, $agreement->api_monthly_credits);

        return 'active';
    }

    /** Called by the shared expiry service while the customer and subscription are locked. */
    public function expireCredits(CustomerServiceSubscription $subscription): void
    {
        $agreement = ServicePlanAgreement::where('subscription_id', $subscription->id)->lockForUpdate()->firstOrFail();
        $this->allocate($agreement, $subscription, 'expiry', $agreement->ends_at, $agreement->ends_at, 0, 0);
        $agreement->update(['status' => 'ended']);
        $this->audit($agreement, 'agreement.expired');
    }

    private function audit(ServicePlanAgreement $agreement, string $action): void
    {
        \App\Models\AdminAuditEvent::create(['admin_id' => null, 'operation_id' => $agreement->operation_id,
            'action' => $action, 'target_type' => ServicePlanAgreement::class, 'target_id' => (string) $agreement->id,
            'after_state' => ['customer_id' => $agreement->customer_id, 'subscription_id' => $agreement->subscription_id,
                'status' => $agreement->status, 'execution' => 'approved_agreement_lifecycle']]);
    }

    private function activationBlocked(Customer $customer): bool
    {
        if (app(PaymentCheckoutState::class)->blocker($customer, ServicePlan::class)) {
            return true;
        }
        // A locally free plan is not proof that a retained provider mandate stopped collecting.
        if (Payment::where('customer_id', $customer->id)->where('purchasable_type', ServicePlan::class)
            ->whereNotNull('fib_subscription_id')->whereNotNull('fulfilled_at')
            ->where(fn ($q) => $q->whereNull('provider_subscription_status')->orWhereNotIn('provider_subscription_status', ['CANCELLED', 'CANCELED', 'EXPIRED', 'REJECTED', 'FAILED']))->exists()) {
            return true;
        }
        $current = app(CustomerBillingStateService::class)->resolveActiveServiceSubscription($customer);

        return $current && ! $current->servicePlan?->is_free
            && CustomerServiceSubscription::whereKey($current->id)->excludingComplimentary()->exists();
    }

    private function allocate(ServicePlanAgreement $agreement, CustomerServiceSubscription $subscription, string $cycle, $start, $end, int $appCredits, int $apiCredits): void
    {
        $key = 'agreement:'.$agreement->id.':'.$cycle;
        if (SubscriptionCreditAllocation::where('subscription_id', $subscription->id)->where('cycle_key', $key)->exists()) {
            return;
        }
        $claim = SubscriptionCreditAllocation::create(['customer_id' => $agreement->customer_id, 'subscription_id' => $subscription->id,
            'cycle_key' => $key, 'allocation_type' => $cycle === 'expiry' ? 'agreement_expiry' : 'agreement_monthly',
            'cycle_started_at' => $start, 'paid_through' => null]); // This claim proves access allocation, not collection.
        foreach (['app' => $appCredits, 'api' => $apiCredits] as $channel => $allowance) {
            $wallet = CreditWallet::where('customer_id', $agreement->customer_id)->where('wallet_type', $channel)->lockForUpdate()->firstOrFail();
            $before = (int) $wallet->balance_credits;
            $after = $allowance + (int) $wallet->addon_balance_credits;
            $delta = $after - $before;
            $wallet->update(['subscription_balance_credits' => $allowance, 'balance_credits' => $after,
                'lifetime_earned' => (int) $wallet->lifetime_earned + $allowance,
                'cycle_started_on' => $start->toDateString(), 'cycle_ends_on' => ($cycle === 'expiry' ? $end : $end->copy()->subDay())->toDateString(),
                'current_cycle_key' => $start->format('Y-m'), 'last_granted_at' => now()]);
            CreditLedger::create(['customer_id' => $agreement->customer_id, 'wallet_type' => $channel,
                'type' => $cycle === 'expiry' ? 'subscription_expiry' : 'monthly_refill',
                'source_type' => self::SOURCE, 'source_id' => (string) $agreement->id,
                'direction' => $delta < 0 ? 'debit' : 'credit', 'amount' => abs($delta), 'bucket' => 'subscription',
                'credits_delta' => $delta, 'balance_before' => $before, 'balance_after' => $after,
                'subscription_balance_after' => $allowance, 'addon_balance_after' => $wallet->addon_balance_credits,
                'related_type' => CustomerServiceSubscription::class, 'related_id' => (string) $subscription->id,
                'reference_code' => $key.':'.$channel, 'description' => __($cycle === 'expiry' ? 'agreement.ledger_expiry' : 'agreement.ledger_monthly'),
                'meta' => ['agreement_id' => $agreement->id, 'operation_id' => $agreement->operation_id, 'allocation_id' => $claim->id],
            ]);
        }
        $claim->update(['status' => 'applied', 'applied_at' => now()]);
    }
}

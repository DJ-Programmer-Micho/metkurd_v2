<?php

namespace App\Services\Admin;

use App\Domain\Payments\Actions\FulfillAddonCredits;
use App\Domain\Payments\Actions\FulfillStorageSubscription;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Enums\PaymentRecurringStrategy;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\CreditService;
use App\Services\Billing\ManualServicePlanGrantService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\AddonPurchaseService;
use App\Support\Admin\AdminAccess;
use Illuminate\Validation\ValidationException;

class AdminFinancialCorrections
{
    public function plan(string $id, int $customerId, int $planId, string $cycle, string $reason): array
    {
        return app(AdminOperationRunner::class)->run($id, 'admin.finance', 'grant.plan', $customerId,
            ['plan_id' => $planId, 'cycle' => $cycle, 'wallets' => ['app', 'api']], $reason,
            function (Customer $customer) use ($planId, $cycle, $reason, $id) {
                $plan = ServicePlan::query()->lockForUpdate()->where('is_active', true)->findOrFail($planId);
                $this->cycle($plan, $cycle);
                $old = $customer->currentServicePlan();
                $allowances = ['app' => $old?->appMonthlyCredits() ?? 0, 'api' => $old?->apiMonthlyCredits() ?? 0];
                $subscription = app(ManualServicePlanGrantService::class)->grant($customer, $plan, [
                    'billing_cycle' => $cycle, 'admin_id' => auth('admin')->id(), 'reason' => $reason, 'operation_id' => $id,
                ]);
                $result = $this->sync($customer->fresh(), $plan, $subscription, $id, $reason, $allowances, 'admin_manual_grant');

                return [...$result, 'subscription_id' => $subscription->id,
                    'cycle_started_on' => $subscription->cycle_started_on?->toDateString(),
                    'cycle_ends_on' => $subscription->cycle_ends_on?->toDateString()];
            });
    }

    public function credits(string $id, int $customerId, string $reason): array
    {
        AdminAccess::authorize('admin.finance');
        $existing = \App\Models\AdminOperation::find($id);
        $target = Customer::findOrFail($customerId)->activeServiceSubscription()->first();
        $subscriptionId = data_get($existing?->requested, 'subscription_id', $target?->id);
        $planId = data_get($existing?->requested, 'plan_id', $target?->service_plan_id);

        return app(AdminOperationRunner::class)->run($id, 'admin.finance', 'sync.credits', $customerId,
            ['wallets' => ['app', 'api'], 'target' => 'current_subscription', 'subscription_id' => $subscriptionId, 'plan_id' => $planId], $reason,
            function (Customer $customer) use ($id, $reason, $subscriptionId, $planId) {
                $subscription = $customer->activeServiceSubscription()->lockForUpdate()->with('servicePlan')->first();
                if (! $subscription || ! $subscription->servicePlan || $subscription->id !== $subscriptionId || $subscription->service_plan_id !== $planId) {
                    throw ValidationException::withMessages(['creditSyncReason' => __('Customer does not have an active service plan to sync from.')]);
                }
                $plan = $subscription->servicePlan;

                $result = $this->sync($customer, $plan, $subscription, $id, $reason,
                    ['app' => $plan->appMonthlyCredits(), 'api' => $plan->apiMonthlyCredits()]);

                return [...$result, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
                    'cycle_started_on' => $subscription->cycle_started_on?->toDateString(),
                    'cycle_ends_on' => $subscription->cycle_ends_on?->toDateString()];
            });
    }

    public function addon(string $id, int $customerId, int $productId, string $reason, string $classification = 'no_revenue', ?int $paymentId = null): array
    {
        return app(AdminOperationRunner::class)->run($id, 'admin.finance', 'grant.addon', $customerId,
            ['product_id' => $productId, 'wallet' => 'app', 'classification' => $classification, 'payment_id' => $paymentId], $reason,
            function (Customer $customer) use ($productId, $reason, $id, $classification, $paymentId) {
                $product = CreditProduct::query()->lockForUpdate()->where('is_active', true)->findOrFail($productId);
                if ($classification === 'verified_paid') {
                    return $this->paid($customer, $product, $paymentId, FulfillAddonCredits::class);
                }
                $this->noRevenue($classification, $paymentId);
                $order = app(AddonPurchaseService::class)->purchase($customer, $product->id, $this->grantMeta($id, $reason));

                return ['order_id' => $order->id, 'wallet' => 'app', 'credits' => $order->credits_amount, 'revenue_excluded' => true];
            });
    }

    public function storage(string $id, int $customerId, int $planId, string $cycle, string $reason, string $classification = 'no_revenue', ?int $paymentId = null): array
    {
        return app(AdminOperationRunner::class)->run($id, 'admin.finance', 'grant.storage', $customerId,
            ['plan_id' => $planId, 'cycle' => $cycle, 'classification' => $classification, 'payment_id' => $paymentId], $reason,
            function (Customer $customer) use ($planId, $cycle, $reason, $id, $classification, $paymentId) {
                $plan = StoragePlan::query()->lockForUpdate()->where('is_active', true)->findOrFail($planId);
                $this->cycle($plan, $cycle);
                if ($classification === 'verified_paid') {
                    return $this->paid($customer, $plan, $paymentId, FulfillStorageSubscription::class, $cycle);
                }
                $this->noRevenue($classification, $paymentId);
                $subscription = app(PlanSwitcher::class)->switchStoragePlan($customer, $plan->id, [
                    ...$this->grantMeta($id, $reason), 'billing_cycle' => $cycle,
                    'renewal_strategy' => PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                    'active_until' => $cycle === 'yearly' ? now()->addYearNoOverflow() : now()->addMonthNoOverflow(),
                ]);

                return ['subscription_id' => $subscription->id, 'order_id' => data_get($subscription->meta, 'order_id'),
                    'quota_mb' => $plan->quota_mb, 'cycle_ends_on' => $subscription->cycle_ends_on?->toDateString(), 'revenue_excluded' => true];
            });
    }

    private function sync(Customer $customer, ServicePlan $plan, $subscription, string $id, string $reason, array $allowances, string $source = 'admin_credit_sync'): array
    {
        return app(CreditService::class)->syncCustomerSubscriptionCreditsToPlan($customer, $plan, [
            'type' => 'admin_credit_sync', 'source_type' => $source, 'source_id' => (string) $subscription->id,
            'related_type' => $subscription::class, 'related_id' => (string) $subscription->id,
            'reference_code' => 'ADMIN-'.$id, 'admin_id' => auth('admin')->id(), 'admin_note' => $reason, 'operation_id' => $id,
            'description' => $reason, 'cycle_started_on' => $subscription->cycle_started_on?->toDateString(),
            'cycle_ends_on' => $subscription->cycle_ends_on?->toDateString(),
            'current_cycle_key' => $subscription->cycle_started_on?->format('Y-m') ?? now()->format('Y-m'),
            'current_allowances' => $allowances,
        ]);
    }

    private function grantMeta(string $id, string $reason): array
    {
        return ['provider' => 'admin_manual', 'payment_method' => 'admin_manual', 'provider_ref' => 'ADMIN-'.$id,
            'billing_source' => 'internal_non_revenue', 'revenue_excluded' => true, 'revenue_record' => false,
            'admin_adjustment' => true, 'admin_id' => auth('admin')->id(), 'admin_note' => $reason,
            'operation_id' => $id, 'ui' => 'admin.customers.register'];
    }

    private function noRevenue(string $classification, ?int $paymentId): void
    {
        if ($classification !== 'no_revenue' || $paymentId !== null) {
            throw ValidationException::withMessages(['classification' => __('admin_p0.classification_invalid')]);
        }
    }

    private function cycle($plan, string $cycle): void
    {
        if (! in_array($cycle, ['monthly', 'yearly'], true) || ! $plan->supportsBillingInterval($cycle)) {
            throw ValidationException::withMessages(['billingCycle' => __('admin_p0.cycle_invalid')]);
        }
    }

    private function paid(Customer $customer, $product, ?int $paymentId, string $fulfiller, ?string $cycle = null): array
    {
        AdminAccess::authorize('admin.reconcile');
        $payment = Payment::query()->lockForUpdate()->find($paymentId);
        if (! $payment || in_array($payment->status, [PaymentStatus::REFUNDED, PaymentStatus::REFUND_REQUESTED], true)
            || (int) $payment->customer_id !== (int) $customer->id
            || $payment->purchasable_type !== $product->getMorphClass() || (int) $payment->purchasable_id !== (int) $product->id
            || ($cycle && data_get($payment->purchase_snapshot, 'billing_cycle', 'monthly') !== $cycle)) {
            throw ValidationException::withMessages(['paymentId' => __('admin_p0.evidence_mismatch')]);
        }
        if ($payment->fulfilled_at) {
            return ['payment_id' => $payment->id, 'already_fulfilled' => true];
        }
        $status = app(AdminProviderEvidence::class)->verify($payment, $payment->providerReference());
        $payment->forceFill(['status' => PaymentStatus::PAID, 'internal_status' => PaymentInternalStatus::PAID_PENDING_APPLICATION,
            'paid_at' => $payment->paid_at ?? ($status->paidAt ?? $status->lastPaymentAt ?? now()),
            'status_response' => $status->raw, 'last_status_checked_at' => now()])->save();
        app($fulfiller)->handle($payment);
        if (! $payment->fresh()->fulfilled_at) {
            throw ValidationException::withMessages(['paymentId' => __('admin_p0.state_changed')]);
        }

        return ['payment_id' => $payment->id, 'order_id' => CreditOrder::where('payment_id', $payment->id)->latest('id')->value('id'), 'revenue_excluded' => false];
    }
}

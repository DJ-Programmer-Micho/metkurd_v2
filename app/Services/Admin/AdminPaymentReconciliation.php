<?php

namespace App\Services\Admin;

use App\Domain\Payments\Actions\FulfillAddonCredits;
use App\Domain\Payments\Actions\FulfillPlanSubscription;
use App\Domain\Payments\Actions\FulfillStorageSubscription;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Illuminate\Validation\ValidationException;

class AdminPaymentReconciliation
{
    public function handle(string $id, int $customerId, int $paymentId, string $candidate, string $mode, string $reason, bool $referenceCorrection = false): Payment
    {
        app(AdminOperationRunner::class)->run($id, 'admin.reconcile', 'payment.reconcile', $customerId,
            ['payment_id' => $paymentId, 'candidate' => $candidate, 'mode' => $mode, 'reference_correction' => $referenceCorrection], $reason,
            function (Customer $customer) use ($paymentId, $candidate, $mode, $reason, $id, $referenceCorrection) {
                $payment = Payment::query()->lockForUpdate()->with(['customer', 'purchasable'])->findOrFail($paymentId);
                if ((int) $payment->customer_id !== (int) $customer->id
                    || ! in_array($mode, ['manual_correction_already_applied', 'apply_fulfillment_once'], true)
                    || in_array($payment->status, [PaymentStatus::REFUNDED, PaymentStatus::REFUND_REQUESTED], true)
                    || ($mode === 'apply_fulfillment_once' && $payment->fulfilled_at !== null)) {
                    $this->reject();
                }
                $before = ['status' => $payment->status->value, 'internal_status' => $payment->internal_status?->value,
                    'fib_payment_id' => $payment->fib_payment_id, 'fib_subscription_id' => $payment->fib_subscription_id];
                // GET verification is read-only. No candidate/state is persisted until all evidence and local checks pass.
                $evidence = app(AdminProviderEvidence::class)->verify($payment, $candidate);
                $subscription = null;
                if ($mode === 'manual_correction_already_applied') {
                    if ($evidence instanceof FibSubscriptionStatusData) {
                        $subscription = $this->noRefillSubscription($customer, $payment);
                    } elseif ($payment->fulfilled_at === null) {
                        $this->reject();
                    }
                }
                $column = $payment->isProviderSubscriptionObject() ? 'fib_subscription_id' : 'fib_payment_id';
                $payment->forceFill([
                    $column => $candidate, 'status' => PaymentStatus::PAID,
                    'provider_status' => $evidence->status,
                    'provider_payment_status' => 'PAID',
                    'paid_at' => $payment->paid_at ?? ($evidence->paidAt ?? $evidence->lastPaymentAt ?? now()),
                    'status_response' => $evidence->raw, 'last_status_checked_at' => now(),
                ]);
                if ($evidence instanceof FibSubscriptionStatusData) {
                    $payment->forceFill(['provider_subscription_status' => $evidence->status,
                        'last_payment_at' => $evidence->lastPaymentAt, 'active_until' => $evidence->activeUntil]);
                }
                $payment->save();
                if ($subscription) {
                    $cycleKey = $evidence->lastPaymentAt ? 'fib:'.$candidate.':'.$evidence->lastPaymentAt->copy()->utc()->format('Y-m-d\TH:i:s\Z') : $payment->providerRecurringCycleKey();
                    $subscription->forceFill([
                        'payment_id' => $payment->id, 'source' => 'fib', 'provider_ref' => $candidate,
                        'auto_renew' => true, 'renewal_strategy' => PaymentRecurringStrategy::PROVIDER_SCHEDULE->value,
                        'cycle_started_on' => ($evidence->lastPaymentAt ?? $subscription->cycle_started_on)?->toDateString(),
                        'cycle_ends_on' => ($evidence->activeUntil ?? $subscription->cycle_ends_on)?->toDateString(),
                        'next_renewal_on' => ($evidence->activeUntil ?? $subscription->next_renewal_on)?->toDateString(),
                        'meta' => array_merge($subscription->meta ?? [], [
                            'billing_source' => 'admin_paid_reconciliation', 'revenue_record' => true, 'provider' => 'fib',
                            'fib_subscription_id' => $candidate, 'manual_correction_already_applied' => true,
                            'no_credit_refill' => true, 'admin_id' => auth('admin')->id(), 'reason' => $reason,
                            'operation_id' => $id, 'provider_cycle_key' => $cycleKey,
                        ]),
                    ])->save();
                    $customer->syncResolvedServicePlan($subscription);
                    $payment->forceFill(['internal_status' => PaymentInternalStatus::APPLIED,
                        'fulfilled_at' => $payment->fulfilled_at ?? now(), 'review_required_at' => null, 'mismatch_reason' => null])->save();
                } elseif ($mode === 'apply_fulfillment_once') {
                    $fulfiller = match ($payment->purchase_type) {
                        PurchaseType::PLAN_SUBSCRIPTION => FulfillPlanSubscription::class,
                        PurchaseType::STORAGE_SUBSCRIPTION => FulfillStorageSubscription::class,
                        PurchaseType::ADDON_CREDITS => FulfillAddonCredits::class,
                    };
                    app($fulfiller)->handle($payment);
                }
                $payment->refresh();
                if (! $payment->fulfilled_at) {
                    $this->reject();
                }
                $meta = ['billing_source' => 'admin_paid_reconciliation', 'revenue_record' => true,
                    'provider' => 'fib', 'manual_correction_already_applied' => $mode === 'manual_correction_already_applied',
                    'no_credit_refill' => $mode === 'manual_correction_already_applied', 'admin_id' => auth('admin')->id(),
                    'reason' => $reason, 'operation_id' => $id, 'reconciled_at' => now()->toIso8601String()];
                if ($referenceCorrection) {
                    $meta['review_resolution'] = ['action' => 'attach_correct_provider_reference', 'mode' => $mode,
                        'admin_id' => auth('admin')->id(), 'reason' => $reason, 'operation_id' => $id,
                        'closed_at' => now()->toIso8601String()];
                }
                $payment->forceFill(['meta' => array_merge($payment->meta ?? [], $meta, ['admin_paid_reconciliation' => [
                    'mode' => $mode, 'reason' => $reason, 'admin_id' => auth('admin')->id(), 'operation_id' => $id,
                    'reconciled_at' => now()->toIso8601String(),
                ]])])->save();
                app(PaymentEventRecorder::class)->record($payment, [
                    'event_type' => 'operator_subscription_reconciled', 'source' => 'admin_paid_subscription_reconciliation',
                    'event_key' => 'admin-reconciliation:'.$id, 'before_status' => $before['status'], 'after_status' => $payment->status->value,
                    'meta' => ['admin_id' => auth('admin')->id(), 'operation_id' => $id, 'reason' => $reason,
                        'mode' => $mode, 'before' => $before, 'no_credit_refill' => $mode === 'manual_correction_already_applied'],
                ]);

                return ['payment_id' => $payment->id, 'status' => $payment->status->value, 'internal_status' => $payment->internal_status?->value];
            });

        return Payment::query()->with(['customer', 'purchasable'])->findOrFail($paymentId);
    }

    private function noRefillSubscription(Customer $customer, Payment $payment): CustomerServiceSubscription
    {
        $plan = $payment->purchasable;
        $subscription = $customer->activeServiceSubscription()->lockForUpdate()->first();
        if (! $plan instanceof ServicePlan || ! $subscription || (int) $subscription->service_plan_id !== (int) $plan->id
            || (int) $customer->currentServicePlanId() !== (int) $plan->id
            || ($subscription->payment_id !== null && (int) $subscription->payment_id !== (int) $payment->id)
            || (int) ($customer->wallet()->lockForUpdate()->first()?->subscription_balance_credits ?? 0) < $plan->appMonthlyCredits()
            || (int) ($customer->apiWallet()->lockForUpdate()->first()?->subscription_balance_credits ?? 0) < $plan->apiMonthlyCredits()) {
            $this->reject();
        }

        return $subscription;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['providerReference' => __('admin_p0.state_changed')]);
    }
}

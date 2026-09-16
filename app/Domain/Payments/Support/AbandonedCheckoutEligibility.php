<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Services\Admin\AdminProviderEvidence;

class AbandonedCheckoutEligibility
{
    /** Display hint and locked mutation share the same conservative local policy. */
    public function adminEligible(Payment $payment): bool
    {
        if (app(PaymentCheckoutState::class)->state($payment) !== 'review'
            || app(PaymentCheckoutState::class)->deadline($payment)?->isFuture()
            || $payment->isApplied() || $payment->paid_at || $payment->last_payment_at || $payment->active_until
            || ! in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::AWAITING_CUSTOMER_ACTION, PaymentStatus::FAILED, PaymentStatus::CANCELED], true)
            || in_array($payment->internal_status, [PaymentInternalStatus::APPLIED, PaymentInternalStatus::PAID_PENDING_APPLICATION, PaymentInternalStatus::REFUND_REQUESTED, PaymentInternalStatus::REFUNDED], true)
            || app(AdminProviderEvidence::class)->preventsCheckoutInvalidation($payment)) {
            return false;
        }
        // Retained fulfillment/obligation links outrank incomplete Payment markers.
        foreach ([\App\Models\CustomerServiceSubscription::class, \App\Models\CustomerStorageSubscription::class, \App\Models\CreditOrder::class, \App\Models\SubscriptionCreditAllocation::class] as $model) {
            $links = $model::where('payment_id', $payment->id);
            if (in_array($model, [\App\Models\CustomerServiceSubscription::class, \App\Models\CustomerStorageSubscription::class], true) && $payment->fib_subscription_id) {
                $links->orWhere(fn ($query) => $query->where('customer_id', $payment->customer_id)
                    ->where('provider_ref', $payment->fib_subscription_id));
            }
            if ($links->exists()) {
                return false;
            }
        }

        return ! \App\Models\CouponRedemption::where('payment_id', $payment->id)
            ->where('status', \App\Enums\CouponRedemptionStatus::CONSUMED)->exists();
    }

    /** Customers cannot resolve unknown provider obligations or unexplained manual review. */
    public function customerEligible(Payment $payment): bool
    {
        if (! $this->adminEligible($payment)
            || $payment->provider !== \App\Domain\Payments\Enums\PaymentProvider::FIB
            || ! in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::AWAITING_CUSTOMER_ACTION], true)
            || $payment->review_required_at || $payment->internal_status === PaymentInternalStatus::REQUIRES_REVIEW
            || trim((string) $payment->mismatch_reason) !== '' || trim((string) $payment->status_reason) !== ''
            || app(PaymentCheckoutState::class)->deadline($payment) !== null) {
            return false;
        }
        // Missing expiry does not prove an issued remote checkout can no longer collect.
        // Such objects remain on the existing provider/Admin cancellation path.
        if ($payment->fib_payment_id || $payment->fib_subscription_id || $payment->readable_code
            || $payment->qr_code || $payment->provider_links
            || data_get($payment->create_response, 'id') || data_get($payment->status_response, 'id')
            || $payment->callback_payload || $payment->cancel_response) {
            return false;
        }
        $statuses = array_filter(array_map(fn ($s) => strtoupper(trim((string) $s)),
            [$payment->provider_status, $payment->provider_payment_status, $payment->provider_subscription_status]));
        if (! $statuses || array_diff($statuses, ['DRAFT', 'UNPAID', 'CREATED'])) {
            return false;
        }
        foreach ([$payment->meta, $payment->create_response, $payment->status_response, $payment->callback_payload, $payment->cancel_response] as $payload) {
            if ($this->ambiguous((array) $payload)) {
                return false;
            }
        }
        foreach ($payment->events()->lazyById(100) as $event) {
            if (! in_array($event->event_type, ['local_payment_created', 'provider_payment_created', 'provider_subscription_created', 'provider_status_checked'], true)
                || $this->ambiguous(['before_status' => $event->before_status, 'after_status' => $event->after_status,
                    'payload' => $event->payload, 'meta' => $event->meta])) {
                return false;
            }
        }

        return true;
    }

    private function ambiguous(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $key = strtolower(str_replace('_', '', (string) $key));
            if ($value !== null && $value !== '' && $value !== false && $value !== []
                && preg_match('/failure|error|refund|revers|chargeback|collection|transaction|paiduntil|paidthrough|captured|settled|paymentid|subscriptionid/', $key)) {
                return true;
            }
            if (is_array($value) && $this->ambiguous($value)) {
                return true;
            }
            if (str_ends_with($key, 'status') && is_scalar($value)
                && ! in_array(strtoupper(trim((string) $value)), ['', 'DRAFT', 'UNPAID', 'CREATED', 'PENDING', 'AWAITING_CUSTOMER_ACTION'], true)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\AbandonedCheckoutEligibility;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Customer;
use App\Services\Coupons\CouponRedemptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AbandonCustomerCheckout
{
    public function handle(int $paymentId): Payment
    {
        abort_unless(auth('app')->check(), 403);
        $customerId = (int) auth('app')->id();

        return DB::transaction(function () use ($paymentId, $customerId) {
            $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $customer->status === 1, 403);
            $payment = Payment::where('customer_id', $customerId)->lockForUpdate()->findOrFail($paymentId);
            // The owned Payment is the durable intent: retry cannot close a different checkout.
            $eventKey = 'customer-abandon-checkout:'.$payment->id;
            if ($payment->events()->where('event_key', $eventKey)->exists()) {
                return $payment;
            }
            if (! app(AbandonedCheckoutEligibility::class)->customerEligible($payment)) {
                throw ValidationException::withMessages(['status' => __('payment_v2.operator_review_help')]);
            }
            $before = $payment->status->value;
            $payment->forceFill([
                'status' => PaymentStatus::CANCELED, 'internal_status' => PaymentInternalStatus::CANCELED,
                'canceled_at' => now(), 'review_required_at' => null,
                'meta' => array_merge($payment->meta ?? [], ['customer_checkout_resolution' => [
                    'action' => 'abandon_unpaid_checkout', 'customer_id' => $customerId,
                    'event_key' => $eventKey, 'closed_at' => now()->toIso8601String(),
                ]]),
            ])->save();
            app(PaymentEventRecorder::class)->record($payment, [
                'event_type' => 'customer_checkout_abandoned', 'source' => 'v2_customer_checkout',
                'event_key' => $eventKey, 'before_status' => $before, 'after_status' => 'canceled',
                'meta' => ['customer_id' => $customerId, 'reason' => 'unpaid_checkout_unavailable', 'local_only' => true],
            ]);
            app(CouponRedemptionService::class)->releaseForPayment($payment, 'customer_abandoned_checkout');

            return $payment;
        });
    }
}

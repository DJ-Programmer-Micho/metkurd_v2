<?php

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use App\Services\Coupons\CouponRedemptionService;
use Illuminate\Support\Facades\DB;

/** Customer checkout lifetime, distinct from a paid subscription's active_until. */
class PaymentCheckoutState
{
    public function state(Payment $payment): string
    {
        if ($payment->status === PaymentStatus::REFUNDED) {
            return 'refunded';
        }
        if ($payment->isApplied()) {
            return 'completed';
        }
        $status = (string) $payment->getRawOriginal('status');
        $internal = (string) $payment->getRawOriginal('internal_status');
        if ($payment->review_required_at || in_array($internal, ['requires_review', 'refund_requested'], true) || $status === 'refund_requested') {
            return 'review';
        }
        if ($status === 'paid' || $internal === 'paid_pending_application') {
            return 'confirming';
        }
        $provider = strtoupper(trim((string) $payment->providerStatusLabel()));
        $charge = strtoupper(trim((string) $payment->providerPaymentStatusLabel()));
        if ($payment->paid_at || $payment->last_payment_at || $payment->active_until
            || in_array($charge, ['PAID', 'APPROVED', 'CONFIRMED', 'CAPTURED', 'SETTLED', 'COMPLETED', 'SUCCESS', 'SUCCESSFUL'], true)
            || in_array($provider, ['PAID', 'ACTIVE', 'SUBSCRIBED', 'COMPLETED'], true)
            || in_array(strtoupper((string) $payment->provider_subscription_status), ['ACTIVE', 'SUBSCRIBED', 'PAID'], true)) {
            return 'review';
        }
        foreach (['failed', 'canceled', 'expired', 'refunded'] as $terminal) {
            if ($status === $terminal || $internal === $terminal) {
                return $terminal;
            }
        }
        if ($payment->expired_at || in_array($provider, ['EXPIRED', 'TIMED_OUT'], true)) {
            return 'expired';
        }
        if ($payment->canceled_at || in_array($provider, ['CANCELED', 'CANCELLED'], true)) {
            return 'canceled';
        }
        if (in_array($provider, ['FAILED', 'DECLINED', 'REJECTED'], true)) {
            return 'failed';
        }
        if (! in_array($status, ['pending', 'awaiting_customer_action'], true)) {
            return 'review';
        }
        if (! in_array($provider, ['', 'UNPAID', 'PENDING', 'CREATED', 'DRAFT', 'INITIATED'], true)) {
            return 'review';
        }
        // This is the provider's checkout deadline, never an age heuristic or paid term end.
        if ($this->deadline($payment)?->lessThanOrEqualTo(now())) {
            return 'expired';
        }
        if (! $this->deadline($payment) || trim((string) ($payment->isProviderSubscriptionObject() ? $payment->fib_subscription_id : $payment->fib_payment_id)) === '') {
            return 'review';
        }

        return 'awaiting';
    }

    public function deadline(Payment $payment): ?\Carbon\CarbonInterface
    {
        // Raw provider timestamps retain their original offset, including historical create responses.
        foreach ([$payment->status_response, $payment->create_response] as $response) {
            if (is_array($response) && array_key_exists('validUntil', $response) && $response['validUntil'] !== null) {
                return FibSubscriptionTimestamp::parse($response['validUntil']);
            }
        }

        return $payment->valid_until?->copy();
    }

    public function blocks(Payment $payment): bool
    {
        return $payment->isCurrentBillingPeriod() && in_array($this->state($payment), ['awaiting', 'confirming', 'review'], true);
    }

    public function blocker(Customer $customer, string $model): ?Payment
    {
        foreach (Payment::currentBillingPeriod()->where('customer_id', $customer->id)->where('purchasable_type', $model)
            ->whereNull('fulfilled_at')->orderByDesc('id')->lazy(100) as $payment) {
            if ($this->blocks($payment)) {
                return $payment;
            }
        }

        return null;
    }

    public function canPoll(Payment $payment): bool
    {
        return $payment->isCurrentBillingPeriod() && $this->state($payment) === 'awaiting';
    }

    /** Persist known unpaid closure at a mutation/reconciliation boundary. GETs stay read-only. */
    public function closeKnownCheckout(Payment $payment): Payment
    {
        if (! $payment->isCurrentBillingPeriod()) {
            return $payment;
        }
        if (! in_array($this->state($payment), ['expired', 'failed', 'canceled'], true) || ! in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::AWAITING_CUSTOMER_ACTION], true)) {
            return $payment;
        }

        return DB::transaction(function () use ($payment) {
            Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($this->state($locked), ['expired', 'failed', 'canceled'], true) || ! in_array($locked->status, [PaymentStatus::PENDING, PaymentStatus::AWAITING_CUSTOMER_ACTION], true)) {
                return $locked;
            }
            $before = $locked->status->value;
            $state = $this->state($locked);
            $next = PaymentStatus::from($state);
            if (! PaymentTransitions::canTransition($locked->status, $next)) {
                return $locked;
            }
            $timestamp = match ($state) {
                'expired' => 'expired_at', 'canceled' => 'canceled_at', default => 'failed_at'
            };
            $locked->forceFill(['status' => $next, 'internal_status' => PaymentInternalStatus::from($state),
                $timestamp => $locked->$timestamp ?? ($state === 'expired' ? $this->deadline($locked)?->setTimezone(config('app.timezone')) : null) ?? now()])->save();
            app(PaymentEventRecorder::class)->record($locked, ['event_type' => 'checkout_closed', 'source' => 'checkout_lifetime',
                'event_key' => 'checkout-closed:'.$locked->id, 'before_status' => $before, 'after_status' => $state]);
            app(CouponRedemptionService::class)->releaseForPayment($locked, 'payment_checkout_terminal');

            return $locked;
        });
    }
}

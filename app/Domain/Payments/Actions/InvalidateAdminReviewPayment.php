<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Services\Admin\AdminOperationRunner;
use App\Services\Coupons\CouponRedemptionService;
use App\Support\Admin\AdminAccess;
use Illuminate\Validation\ValidationException;

class InvalidateAdminReviewPayment
{
    public function eligible(Payment $payment): bool
    {
        return app(\App\Domain\Payments\Support\AbandonedCheckoutEligibility::class)->adminEligible($payment);
    }

    public function handle(string $operationId, int $customerId, int $paymentId, string $reason): Payment
    {
        AdminAccess::authorize('admin.finance');
        $reason = trim($reason);
        app(AdminOperationRunner::class)->run($operationId, 'admin.reconcile', 'payment.invalidate', $customerId,
            ['payment_id' => $paymentId], $reason, function () use ($paymentId, $customerId, $reason, $operationId) {
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                if ((int) $payment->customer_id !== $customerId || ! $this->eligible($payment)) {
                    throw ValidationException::withMessages(['reviewPaymentId' => __('admin_p0.invalidation')]);
                }
                $before = $payment->status->value;
                $payment->forceFill([
                    'status' => PaymentStatus::EXPIRED, 'internal_status' => PaymentInternalStatus::EXPIRED,
                    'expired_at' => now(), 'review_required_at' => null, 'mismatch_reason' => $reason,
                    'meta' => array_merge($payment->meta ?? [], ['review_resolution' => [
                        'action' => 'mark_invalid_expired', 'reason' => $reason, 'admin_id' => auth('admin')->id(),
                        'operation_id' => $operationId, 'closed_at' => now()->toIso8601String(),
                    ]]),
                ])->save();
                app(PaymentEventRecorder::class)->record($payment, [
                    'event_type' => 'admin_payment_marked_invalid', 'source' => 'admin_customer_register_review',
                    'event_key' => 'admin-invalidate:'.$operationId, 'before_status' => $before, 'after_status' => $payment->status->value,
                    'meta' => ['admin_id' => auth('admin')->id(), 'reason' => $reason, 'operation_id' => $operationId],
                ]);
                app(CouponRedemptionService::class)->releaseForPayment($payment, 'admin_abandoned_checkout');

                return ['payment_id' => $payment->id];
            });

        return Payment::query()->findOrFail($paymentId);
    }
}

<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibMapper;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibStatusReasonParser;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Domain\Payments\Support\PaymentReconciliationPolicy;
use App\Domain\Payments\Support\PaymentTransitions;
use App\Events\Payments\PaymentConfirmed;
use App\Services\Billing\SyncProviderSubscriptionLifecycle;
use App\Services\Coupons\CouponRedemptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncFibCheckoutStatus
{
    public function __construct(
        protected FibOneTimePaymentService $oneTime,
        protected FibSubscriptionService $subscriptions,
        protected FibMapper $paymentMapper,
        protected FibSubscriptionMapper $subscriptionMapper,
        protected FibStatusReasonParser $statusReasonParser,
        protected PaymentEventRecorder $events,
        protected PaymentReconciliationPolicy $reconciliationPolicy,
        protected SyncProviderSubscriptionLifecycle $lifecycle,
        protected CouponRedemptionService $redemptions,
    ) {}

    public function handle(
        Payment $payment,
        string $source = 'manual_status_refresh',
        ?array $callbackPayload = null,
        bool $dispatchFulfillment = true,
        bool $syncLifecycle = true,
    ): Payment {
        $payment = $payment->fresh() ?? $payment;
        $source = $this->reconciliationPolicy->normalizeScheduledSource($payment, $source);

        if ($this->reconciliationPolicy->isScheduledCheckoutSource($source)
            && ! $this->reconciliationPolicy->canScheduledCheckoutPoll($payment)) {
            return $payment;
        }

        if ($this->reconciliationPolicy->isScheduledRenewalSource($source)
            && ! $this->reconciliationPolicy->canScheduledRenewalPoll($payment)) {
            return $payment;
        }

        $objectType = $payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT;
        $shouldDispatch = false;

        if ($objectType->isSubscription()) {
            $status = $this->subscriptions->getStatus($payment);
            $nextStatus = $this->subscriptionMapper->toLocalStatus($status);
            $audit = [];

            $payment = DB::transaction(function () use ($payment, $status, $nextStatus, $source, $callbackPayload, &$shouldDispatch, &$audit) {
                /** @var Payment $locked */
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                $currentStatus = $locked->status ?? PaymentStatus::PENDING;
                $baseUpdate = $this->subscriptionBaseUpdate($locked, $status, $callbackPayload);
                $correctiveReversion = $this->shouldRevertUnfulfilledPrematurePaid($locked, $nextStatus, $status);
                $requestedTransitionBlocked = $currentStatus !== $nextStatus
                    && ! PaymentTransitions::canTransition($currentStatus, $nextStatus)
                    && ! $correctiveReversion;

                $audit = [
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'provider_status_raw' => $status->status,
                    'provider_reason' => $this->statusReasonParser->reasonFromRaw($status->raw),
                    'provider_error_codes' => $this->statusReasonParser->errorCodesFromRaw($status->raw),
                    'local_previous_status' => $currentStatus->value,
                    'local_requested_status' => $nextStatus->value,
                    'corrective_reversion' => $correctiveReversion,
                    'callback_present' => $callbackPayload !== null,
                ];

                if ($requestedTransitionBlocked) {
                    $locked->forceFill($baseUpdate)->save();
                    $audit['local_new_status'] = $locked->status->value;
                    $audit['transition_ignored'] = true;

                    $this->events->record($locked, [
                        'event_type' => 'provider_status_ignored',
                        'source' => $source,
                        'before_status' => $currentStatus->value,
                        'after_status' => $currentStatus->value,
                        'payload' => $status->raw,
                        'meta' => [
                            'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                            'requested_status' => $nextStatus->value,
                        ],
                    ]);

                    if ($this->isPaidToFailedLikeTransition($currentStatus, $nextStatus)) {
                        Log::warning('Ignored invalid paid-to-terminal FIB subscription transition.', array_merge(
                            $this->auditContext($locked),
                            [
                                'source' => $source,
                                'provider_status_raw' => $status->status,
                                'requested_status' => $nextStatus->value,
                                'fulfilled_at' => optional($locked->fulfilled_at)?->toIso8601String(),
                                'has_provider_charge_evidence' => $this->hasProviderChargeEvidence($status),
                            ],
                        ));
                    }

                    return $locked->fresh();
                }

                $locked->forceFill(array_filter(array_merge($baseUpdate, [
                    'status' => $nextStatus,
                    'internal_status' => $this->resolveInternalStatus($locked, $nextStatus),
                    'paid_at' => $nextStatus === PaymentStatus::PAID
                        ? ($locked->paid_at ?? $status->lastPaymentAt ?? now())
                        : ($correctiveReversion ? null : $locked->paid_at),
                    'canceled_at' => $nextStatus === PaymentStatus::CANCELED ? ($locked->canceled_at ?? now()) : $locked->canceled_at,
                    'expired_at' => $nextStatus === PaymentStatus::EXPIRED ? ($locked->expired_at ?? now()) : $locked->expired_at,
                    'failed_at' => $this->resolvedFailureTimestamp($locked, $nextStatus),
                ]), static fn (mixed $value) => $value !== null))->save();
                $audit['local_new_status'] = $locked->status->value;
                $audit['transition_ignored'] = false;

                $this->events->record($locked, [
                    'event_type' => 'provider_status_checked',
                    'source' => $source,
                    'before_status' => $currentStatus->value,
                    'after_status' => $locked->status->value,
                    'payload' => $status->raw,
                    'meta' => [
                        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                        'callback_present' => $callbackPayload !== null,
                        'corrective_reversion' => $correctiveReversion,
                    ],
                ]);

                $shouldDispatch = $locked->status === PaymentStatus::PAID
                    && $locked->fulfilled_at === null
                    && $locked->internal_status !== PaymentInternalStatus::REQUIRES_REVIEW
                    && $locked->internal_status !== PaymentInternalStatus::APPLIED;

                return $locked->fresh();
            });

            $this->logSyncAudit($payment, $source, $audit);

            if ($shouldDispatch && $dispatchFulfillment) {
                event(new PaymentConfirmed((int) $payment->id));
            } elseif ($payment->fulfilled_at === null && in_array($payment->status, [
                PaymentStatus::FAILED,
                PaymentStatus::CANCELED,
                PaymentStatus::EXPIRED,
            ], true)) {
                $this->redemptions->releaseForPayment($payment, 'subscription_checkout_terminal');
            }

            if ($syncLifecycle) {
                $this->lifecycle->handle($payment, $source);
            }

            return $payment->fresh();
        }

        $status = $this->oneTime->getStatus($payment);
        $nextStatus = $this->paymentMapper->toLocalStatus($status);
        $audit = [];

        $payment = DB::transaction(function () use ($payment, $status, $nextStatus, $source, $callbackPayload, &$shouldDispatch, &$audit) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $currentStatus = $locked->status ?? PaymentStatus::PENDING;
            $baseUpdate = $this->paymentBaseUpdate($status, $callbackPayload);
            $requestedTransitionBlocked = $currentStatus !== $nextStatus
                && ! PaymentTransitions::canTransition($currentStatus, $nextStatus);
            $audit = [
                'provider_object_type' => PaymentProviderObjectType::PAYMENT->value,
                'provider_status_raw' => $status->status,
                'provider_reason' => $this->statusReasonParser->reasonFromRaw($status->raw)
                    ?? $status->decliningReason,
                'provider_error_codes' => $this->statusReasonParser->errorCodesFromRaw($status->raw),
                'local_previous_status' => $currentStatus->value,
                'local_requested_status' => $nextStatus->value,
                'corrective_reversion' => false,
                'callback_present' => $callbackPayload !== null,
            ];

            if ($requestedTransitionBlocked) {
                $locked->forceFill($baseUpdate)->save();
                $audit['local_new_status'] = $locked->status->value;
                $audit['transition_ignored'] = true;

                $this->events->record($locked, [
                    'event_type' => 'provider_status_ignored',
                    'source' => $source,
                    'before_status' => $currentStatus->value,
                    'after_status' => $currentStatus->value,
                    'payload' => $status->raw,
                    'meta' => [
                        'provider_object_type' => PaymentProviderObjectType::PAYMENT->value,
                        'requested_status' => $nextStatus->value,
                    ],
                ]);

                if ($this->isPaidToFailedLikeTransition($currentStatus, $nextStatus)) {
                    Log::warning('Ignored invalid paid-to-terminal FIB one-time transition.', array_merge(
                        $this->auditContext($locked),
                        [
                            'source' => $source,
                            'provider_status_raw' => $status->status,
                            'requested_status' => $nextStatus->value,
                            'fulfilled_at' => optional($locked->fulfilled_at)?->toIso8601String(),
                        ],
                    ));
                }

                return $locked->fresh();
            }

            $locked->forceFill(array_filter(array_merge($baseUpdate, [
                'status' => $nextStatus,
                'internal_status' => $this->resolveInternalStatus($locked, $nextStatus),
                'paid_at' => $nextStatus === PaymentStatus::PAID ? ($locked->paid_at ?? $status->paidAt ?? now()) : $locked->paid_at,
                'canceled_at' => $nextStatus === PaymentStatus::CANCELED ? ($locked->canceled_at ?? now()) : $locked->canceled_at,
                'expired_at' => $nextStatus === PaymentStatus::EXPIRED ? ($locked->expired_at ?? $status->declinedAt ?? now()) : $locked->expired_at,
                'failed_at' => $this->resolvedFailureTimestamp($locked, $nextStatus, $status->declinedAt),
            ]), static fn (mixed $value) => $value !== null))->save();
            $audit['local_new_status'] = $locked->status->value;
            $audit['transition_ignored'] = false;

            $this->events->record($locked, [
                'event_type' => 'provider_status_checked',
                'source' => $source,
                'before_status' => $currentStatus->value,
                'after_status' => $locked->status->value,
                'payload' => $status->raw,
                'meta' => [
                    'provider_object_type' => PaymentProviderObjectType::PAYMENT->value,
                    'callback_present' => $callbackPayload !== null,
                ],
            ]);

            $shouldDispatch = $locked->status === PaymentStatus::PAID
                && $locked->fulfilled_at === null
                && $locked->internal_status !== PaymentInternalStatus::REQUIRES_REVIEW
                && $locked->internal_status !== PaymentInternalStatus::APPLIED;

            return $locked->fresh();
        });

        $this->logSyncAudit($payment, $source, $audit);

        if ($shouldDispatch && $dispatchFulfillment) {
            event(new PaymentConfirmed((int) $payment->id));
        } elseif ($payment->fulfilled_at === null && in_array($payment->status, [
            PaymentStatus::FAILED,
            PaymentStatus::CANCELED,
            PaymentStatus::EXPIRED,
        ], true)) {
            $this->redemptions->releaseForPayment($payment, 'payment_checkout_terminal');
        }

        return $payment->fresh();
    }

    public function handleByFibPaymentId(
        string $fibPaymentId,
        string $source = 'callback',
        ?array $callbackPayload = null,
        bool $dispatchFulfillment = true,
        bool $syncLifecycle = true,
    ): ?Payment {
        $payment = Payment::query()
            ->where('provider_object_type', PaymentProviderObjectType::PAYMENT)
            ->where('fib_payment_id', $fibPaymentId)
            ->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        return $this->handle($payment, $source, $callbackPayload, $dispatchFulfillment, $syncLifecycle);
    }

    public function handleByFibSubscriptionId(
        string $fibSubscriptionId,
        string $source = 'callback',
        ?array $callbackPayload = null,
        bool $dispatchFulfillment = true,
        bool $syncLifecycle = true,
    ): ?Payment {
        $payment = Payment::query()
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->where('fib_subscription_id', $fibSubscriptionId)
            ->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        return $this->handle($payment, $source, $callbackPayload, $dispatchFulfillment, $syncLifecycle);
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentBaseUpdate(FibPaymentStatusData $status, ?array $callbackPayload): array
    {
        $update = [
            'provider_status' => $status->status,
            'provider_payment_status' => $status->status,
            'declining_reason' => $this->paymentMapper->normalizeDecliningReason($status->decliningReason),
            'status_reason' => $this->statusReasonParser->reasonFromRaw($status->raw)
                ?: $status->decliningReason
                ?: $status->status,
            'status_response' => $status->raw,
            'valid_until' => $status->validUntil,
            'last_status_checked_at' => now(),
        ];

        if ($callbackPayload !== null) {
            $update['callback_payload'] = $callbackPayload;
            $update['last_callback_received_at'] = now();
        }

        return $update;
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionBaseUpdate(Payment $payment, FibSubscriptionStatusData $status, ?array $callbackPayload): array
    {
        $update = [
            'provider_status' => $status->status,
            'provider_subscription_status' => $this->subscriptionMapper->normalizeStatus($status->status),
            'status_reason' => $this->statusReasonParser->reasonFromRaw($status->raw) ?: $status->status,
            'status_response' => $status->raw,
            'readable_code' => $status->readableCode ?? $payment->readable_code,
            'provider_links' => $status->providerLinks !== [] ? $status->providerLinks : $payment->provider_links,
            'valid_until' => $status->validUntil ?? $payment->valid_until,
            'active_until' => $status->activeUntil,
            'last_payment_at' => $status->lastPaymentAt,
            'provider_interval' => $status->interval ?? $payment->provider_interval,
            'provider_trial_period' => $status->trialPeriod ?? $payment->provider_trial_period,
            'last_status_checked_at' => now(),
        ];

        if ($callbackPayload !== null) {
            $update['callback_payload'] = $callbackPayload;
            $update['last_callback_received_at'] = now();
        }

        return $update;
    }

    protected function shouldRevertUnfulfilledPrematurePaid(
        Payment $payment,
        PaymentStatus $nextStatus,
        FibSubscriptionStatusData $status,
    ): bool {
        if ($payment->status !== PaymentStatus::PAID || $payment->fulfilled_at !== null) {
            return false;
        }

        if (! $this->isFailedLikeStatus($nextStatus)) {
            return false;
        }

        return ! $this->hasProviderChargeEvidence($status);
    }

    protected function hasProviderChargeEvidence(FibSubscriptionStatusData $status): bool
    {
        return $status->lastPaymentAt !== null;
    }

    protected function isFailedLikeStatus(PaymentStatus $status): bool
    {
        return in_array($status, [
            PaymentStatus::FAILED,
            PaymentStatus::CANCELED,
            PaymentStatus::EXPIRED,
        ], true);
    }

    protected function isPaidToFailedLikeTransition(PaymentStatus $currentStatus, PaymentStatus $nextStatus): bool
    {
        return $currentStatus === PaymentStatus::PAID
            && $this->isFailedLikeStatus($nextStatus);
    }

    protected function resolveInternalStatus(Payment $payment, PaymentStatus $nextStatus): PaymentInternalStatus
    {
        if ($payment->fulfilled_at !== null) {
            return PaymentInternalStatus::APPLIED;
        }

        if ($payment->internal_status === PaymentInternalStatus::REQUIRES_REVIEW && $nextStatus === PaymentStatus::PAID) {
            return PaymentInternalStatus::REQUIRES_REVIEW;
        }

        return PaymentInternalStatus::fromPaymentStatus($nextStatus);
    }

    protected function resolvedFailureTimestamp(
        Payment $payment,
        PaymentStatus $nextStatus,
        mixed $fallback = null,
    ): ?\DateTimeInterface {
        if (! $this->isFailedLikeStatus($nextStatus)) {
            return $payment->failed_at;
        }

        if ($payment->failed_at instanceof \DateTimeInterface) {
            return $payment->failed_at;
        }

        if ($fallback instanceof \DateTimeInterface) {
            return $fallback;
        }

        return now();
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    protected function logSyncAudit(Payment $payment, string $source, array $audit): void
    {
        $previous = (string) ($audit['local_previous_status'] ?? '');
        $new = (string) ($audit['local_new_status'] ?? $payment->status->value);
        $transitionIgnored = (bool) ($audit['transition_ignored'] ?? false);
        $correctiveReversion = (bool) ($audit['corrective_reversion'] ?? false);
        $callbackPresent = (bool) ($audit['callback_present'] ?? false);
        $statusChanged = $previous !== '' && $new !== '' && $previous !== $new;

        if (! $statusChanged && ! $transitionIgnored && ! $correctiveReversion && ! $callbackPresent) {
            return;
        }

        Log::info('FIB payment status sync audit.', array_merge(
            $this->auditContext($payment),
            [
                'source' => $source,
                'provider_object_type' => $audit['provider_object_type'] ?? ($payment->provider_object_type?->value ?? null),
                'provider_status_raw' => $audit['provider_status_raw'] ?? null,
                'provider_reason' => $audit['provider_reason'] ?? null,
                'provider_error_codes' => $audit['provider_error_codes'] ?? [],
                'local_previous_status' => $previous !== '' ? $previous : null,
                'local_requested_status' => $audit['local_requested_status'] ?? null,
                'local_new_status' => $new,
                'transition_ignored' => $transitionIgnored,
                'corrective_reversion' => $correctiveReversion,
                'callback_present' => $callbackPresent,
            ],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditContext(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'payment_uuid' => (string) $payment->uuid,
            'customer_id' => (int) $payment->customer_id,
            'provider_reference' => $payment->providerReference(),
            'vm_hostname' => gethostname() ?: php_uname('n'),
        ];
    }
}

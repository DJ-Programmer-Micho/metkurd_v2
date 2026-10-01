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
use App\Domain\Payments\Support\ProviderObservation;
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
        if (! $payment->isCurrentBillingPeriod()) {
            return $payment;
        }
        $payment = app(\App\Domain\Payments\Support\PaymentCheckoutState::class)->closeKnownCheckout($payment);
        if ($callbackPayload === null && app(\App\Domain\Payments\Support\PaymentCheckoutState::class)->state($payment) === 'expired') {
            return $payment;
        }
        $source = $this->reconciliationPolicy->normalizeScheduledSource($payment, $source);

        if ($callbackPayload === null && ! ProviderObservation::pending($payment) && $dispatchFulfillment
            && $payment->status === PaymentStatus::PAID
            && $payment->fulfilled_at === null
            && $payment->internal_status !== PaymentInternalStatus::REQUIRES_REVIEW
            && $payment->internal_status !== PaymentInternalStatus::APPLIED
            && $this->hasVerifiedStoredEvidence($payment)) {
            Log::info('fib.confirm.replaying_paid_application', array_merge(
                $this->auditContext($payment),
                [
                    'source' => $source,
                    'purchase_type' => $payment->purchase_type?->value,
                    'application_status' => $payment->applicationStatusLabel(),
                    'provider_status' => $payment->providerStatusLabel(),
                ],
            ));

            event(new PaymentConfirmed((int) $payment->id));

            return $payment->fresh();
        }

        if ($this->reconciliationPolicy->isScheduledCheckoutSource($source)
            && ! $this->reconciliationPolicy->canScheduledCheckoutPoll($payment)) {
            return $payment;
        }

        if ($this->reconciliationPolicy->isScheduledRenewalSource($source)
            && ! $this->reconciliationPolicy->canScheduledRenewalPoll($payment)) {
            return $payment;
        }

        $callbackVersion = ProviderObservation::callbackVersion($payment);
        $eventWatermark = (int) $payment->events()->max('id');
        $objectType = $payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT;
        $shouldDispatch = false;
        $rejected = false;

        if ($objectType->isSubscription()) {
            $status = $this->subscriptions->getStatus($payment);
            $audit = [];

            $payment = DB::transaction(function () use ($payment, $status, $source, $callbackPayload, $callbackVersion, $eventWatermark, &$shouldDispatch, &$audit, &$rejected) {
                \App\Models\Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
                /** @var Payment $locked */
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                if (ProviderObservation::callbackVersion($locked) !== $callbackVersion) {
                    $rejected = true;

                    return $locked->fresh();
                }
                $beforeObservation = ProviderObservation::state($locked);
                if ($reason = app(\App\Domain\Payments\Support\FibStatusEvidence::class)->rejection($locked, $status)) {
                    $rejected = true;

                    return $this->rejectEvidence($locked, $reason);
                }
                $currentStatus = $locked->status ?? PaymentStatus::PENDING;
                $nextStatus = $this->resolveSubscriptionStatus($status);
                if ($locked->isFulfilled() && $status->lastPaymentAt
                    && (! $locked->last_payment_at || $status->lastPaymentAt->gt($locked->last_payment_at))
                    && (data_get($locked->meta, 'provider_cancellation.requested_at')
                        || data_get($locked->meta, 'supersession.superseded_at')
                        || $locked->serviceSubscriptions()->where(fn ($q) => $q->where('status', 'ended')->orWhereNotNull('canceled_at'))->exists()
                        || $locked->storageSubscriptions()->where(fn ($q) => $q->where('status', 'ended')->orWhereNotNull('canceled_at'))->exists())) {
                    $rejected = true;
                    $locked->forceFill(['status_response' => $status->raw, 'last_status_checked_at' => now()])->save();

                    return $this->rejectEvidence($locked, 'collection_after_cancellation_or_supersession');
                }
                if ($currentStatus === PaymentStatus::PAID && $locked->fulfilled_at === null
                    && $nextStatus === PaymentStatus::AWAITING_CUSTOMER_ACTION && ! $this->hasProviderChargeEvidence($status)) {
                    $rejected = true;

                    return $this->rejectEvidence($locked, 'unverified_paid_application');
                }
                $baseUpdate = $this->subscriptionBaseUpdate($locked, $status, $callbackPayload);
                $correctiveReversion = $this->shouldRevertUnfulfilledPrematurePaid($locked, $nextStatus, $status);
                $requestedTransitionBlocked = $currentStatus !== $nextStatus
                    && ! PaymentTransitions::canTransition($currentStatus, $nextStatus)
                    && ! $correctiveReversion;
                $providerPaymentStatus = $this->subscriptionMapper->explicitPaidStatusFromPayloads($status);

                $audit = [
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'provider_status_raw' => $status->status,
                    'provider_payment_status' => $providerPaymentStatus,
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

                    ProviderObservation::record($locked, $beforeObservation, $source, $callbackVersion, $eventWatermark, ['transition_ignored' => $requestedTransitionBlocked]);

                    if ($this->isPaidToFailedLikeTransition($currentStatus, $nextStatus)) {
                        Log::warning('Ignored invalid paid-to-terminal FIB subscription transition.', array_merge(
                            $this->auditContext($locked),
                            [
                                'source' => $source,
                                'provider_status_raw' => $status->status,
                                'requested_status' => $nextStatus->value,
                                'fulfilled_at' => optional($locked->fulfilled_at)?->toIso8601String(),
                                'provider_payment_status' => $providerPaymentStatus,
                                'has_provider_charge_evidence' => $this->hasProviderChargeEvidence($status),
                            ],
                        ));
                    }

                    if ($nextStatus === PaymentStatus::PAID && $this->isFailedLikeStatus($currentStatus) && $locked->fulfilled_at === null) {
                        $rejected = true;

                        return $this->rejectEvidence($locked, 'late_paid_closed_checkout');
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

                ProviderObservation::record($locked, $beforeObservation, $source, $callbackVersion, $eventWatermark, ['transition_ignored' => $requestedTransitionBlocked]);

                $shouldDispatch = $locked->status === PaymentStatus::PAID
                    && $locked->fulfilled_at === null
                    && $locked->internal_status !== PaymentInternalStatus::REQUIRES_REVIEW
                    && $locked->internal_status !== PaymentInternalStatus::APPLIED;

                return $locked->fresh();
            });

            if ($rejected) {
                return $payment;
            }

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

            app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->observe($payment, $source);
            if ($syncLifecycle) {
                $this->lifecycle->handle($payment, $source);
            }

            return $payment->fresh();
        }

        $status = $this->oneTime->getStatus($payment);
        $nextStatus = $this->paymentMapper->toLocalStatus($status);
        $audit = [];

        $payment = DB::transaction(function () use ($payment, $status, $nextStatus, $source, $callbackPayload, $callbackVersion, $eventWatermark, &$shouldDispatch, &$audit, &$rejected) {
            \App\Models\Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (ProviderObservation::callbackVersion($locked) !== $callbackVersion) {
                $rejected = true;

                return $locked->fresh();
            }
            $beforeObservation = ProviderObservation::state($locked);
            if ($reason = app(\App\Domain\Payments\Support\FibStatusEvidence::class)->rejection($locked, $status)) {
                $rejected = true;

                return $this->rejectEvidence($locked, $reason);
            }
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

                ProviderObservation::record($locked, $beforeObservation, $source, $callbackVersion, $eventWatermark, ['transition_ignored' => $requestedTransitionBlocked]);

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

                if ($nextStatus === PaymentStatus::PAID && $this->isFailedLikeStatus($currentStatus) && $locked->fulfilled_at === null) {
                    $rejected = true;

                    return $this->rejectEvidence($locked, 'late_paid_closed_checkout');
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

            ProviderObservation::record($locked, $beforeObservation, $source, $callbackVersion, $eventWatermark, ['transition_ignored' => $requestedTransitionBlocked]);

            $shouldDispatch = $locked->status === PaymentStatus::PAID
                && $locked->fulfilled_at === null
                && $locked->internal_status !== PaymentInternalStatus::REQUIRES_REVIEW
                && $locked->internal_status !== PaymentInternalStatus::APPLIED;

            return $locked->fresh();
        });

        if ($rejected) {
            return $payment;
        }

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
    protected function subscriptionBaseUpdate(
        Payment $payment,
        FibSubscriptionStatusData $status,
        ?array $callbackPayload,
    ): array {
        $update = [
            'provider_status' => $status->status,
            'provider_subscription_status' => $this->subscriptionMapper->normalizeStatus($status->status),
            'provider_payment_status' => $this->subscriptionMapper->explicitPaidStatusFromPayloads($status)
                ?: $payment->provider_payment_status,
            'status_reason' => $this->statusReasonParser->reasonFromRaw($status->raw) ?: $status->status,
            'status_response' => $status->raw,
            'readable_code' => $status->readableCode ?? $payment->readable_code,
            'provider_links' => $status->providerLinks !== [] ? $status->providerLinks : $payment->provider_links,
            'valid_until' => $status->validUntil ?? $payment->valid_until,
            'active_until' => $payment->active_until && (! $status->activeUntil || $status->activeUntil->lt($payment->active_until))
                ? $payment->active_until : $status->activeUntil,
            'last_payment_at' => $payment->last_payment_at && (! $status->lastPaymentAt || $status->lastPaymentAt->lt($payment->last_payment_at))
                ? $payment->last_payment_at : $status->lastPaymentAt,
            'provider_interval' => $status->interval ?? $payment->provider_interval,
            'provider_trial_period' => $status->trialPeriod ?? $payment->provider_trial_period,
            'last_status_checked_at' => now(),
        ];

        if ($payment->isFulfilled() && (! $status->lastPaymentAt
            || ($payment->last_payment_at && $status->lastPaymentAt->lte($payment->last_payment_at)))) {
            // A coverage-only observation is not evidence of a further paid cycle.
            $update['active_until'] = $payment->active_until;
        }

        // Persist verified collection separately from the latest (possibly incomplete) observation.
        if ($status->lastPaymentAt && $status->activeUntil && $status->activeUntil->gt($status->lastPaymentAt)
            && (! $payment->last_payment_at || $status->lastPaymentAt->gt($payment->last_payment_at)
                || ($status->lastPaymentAt->equalTo($payment->last_payment_at) && $status->activeUntil->equalTo($payment->active_until)))
            && (! $payment->active_until || $status->activeUntil->gte($payment->active_until))) {
            $update['meta'] = array_merge((array) $payment->meta, ['verified_subscription_collection' => [
                'provider_object_id' => $payment->fib_subscription_id,
                'last_payment_at' => $status->lastPaymentAt->toIso8601String(),
                'paid_through' => $status->activeUntil->toIso8601String(),
            ]]);
        }

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

    protected function hasProviderChargeEvidence(
        FibSubscriptionStatusData $status,
    ): bool {
        if ($status->lastPaymentAt !== null) {
            return true;
        }

        return $this->subscriptionMapper->hasConfirmedPaymentEvidence($status);
    }

    protected function resolveSubscriptionStatus(
        FibSubscriptionStatusData $status,
    ): PaymentStatus {
        $nextStatus = $this->subscriptionMapper->toLocalStatus($status);

        if ($nextStatus !== PaymentStatus::PAID
            && $this->subscriptionMapper->isPaidLifecycleStatusValue($status->status)
            && $this->subscriptionMapper->hasConfirmedPaymentEvidence($status)) {
            return PaymentStatus::PAID;
        }

        return $nextStatus;
    }

    protected function hasVerifiedStoredEvidence(Payment $payment): bool
    {
        if (! is_array($payment->status_response) || $payment->status_response === []) {
            return false;
        }
        try {
            if (data_get($payment->meta, 'provider_observation') !== null) {
                $anchor = $payment->events()->whereIn('event_type', [...ProviderObservation::EVENTS, 'provider_status_checked', 'provider_status_ignored'])->latest('id')->first();
                if (! ProviderObservation::validReceipt($payment, $anchor)) {
                    return false;
                }
            }
            $status = $payment->isProviderSubscriptionObject()
                ? FibSubscriptionStatusData::fromArray($payment->status_response)
                : FibPaymentStatusData::fromArray($payment->status_response);
            if (app(\App\Domain\Payments\Support\FibStatusEvidence::class)->rejection($payment, $status) !== null) {
                return false;
            }

            return $status instanceof FibSubscriptionStatusData
                ? $this->subscriptionMapper->toLocalStatus($status) === PaymentStatus::PAID
                : $this->paymentMapper->toLocalStatus($status) === PaymentStatus::PAID;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function rejectEvidence(Payment $payment, string $reason): Payment
    {
        // Commit safe review evidence without applying the rejected observation.
        $meta = (array) $payment->meta;
        $meta['provider_evidence_rejection'] = $reason;
        $meta['provider_observation']['valid'] = false;
        if ($reason !== 'stale_subscription_observation') {
            $payment->review_required_at ??= now();
            if ($payment->fulfilled_at === null || $reason === 'collection_after_cancellation_or_supersession') {
                $payment->internal_status = PaymentInternalStatus::REQUIRES_REVIEW;
            }
        }
        $payment->forceFill(['meta' => $meta])->save();
        $this->events->record($payment, [
            'event_type' => 'provider_evidence_rejected', 'source' => 'provider_evidence_guard',
            'event_key' => 'evidence-rejected:'.$payment->id.':'.$reason,
            'meta' => ['reason' => $reason],
        ]);

        return $payment->fresh();
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
        if ($payment->internal_status === PaymentInternalStatus::REQUIRES_REVIEW
            && (str_starts_with((string) data_get($payment->meta, 'provider_evidence_rejection'), 'collection_after_')
                || $payment->mismatch_reason === 'collection_after_cancellation_intent')) {
            return PaymentInternalStatus::REQUIRES_REVIEW;
        }
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

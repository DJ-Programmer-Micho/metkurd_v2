<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibMapper;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Domain\Payments\Support\PaymentTransitions;
use App\Events\Payments\PaymentConfirmed;
use App\Services\Billing\SyncProviderSubscriptionLifecycle;
use Illuminate\Support\Facades\DB;

class SyncFibCheckoutStatus
{
    public function __construct(
        protected FibOneTimePaymentService $oneTime,
        protected FibSubscriptionService $subscriptions,
        protected FibMapper $paymentMapper,
        protected FibSubscriptionMapper $subscriptionMapper,
        protected PaymentEventRecorder $events,
        protected SyncProviderSubscriptionLifecycle $lifecycle,
    ) {
    }

    public function handle(Payment $payment, string $source = 'manual_status_refresh', ?array $callbackPayload = null): Payment
    {
        $payment = $payment->fresh() ?? $payment;
        $objectType = $payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT;
        $shouldDispatch = false;

        if ($objectType->isSubscription()) {
            $status = $this->subscriptions->getStatus($payment);
            $nextStatus = $this->subscriptionMapper->toLocalStatus($status);

            $payment = DB::transaction(function () use ($payment, $status, $nextStatus, $source, $callbackPayload, &$shouldDispatch) {
                /** @var Payment $locked */
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                $currentStatus = $locked->status ?? PaymentStatus::PENDING;
                $baseUpdate = $this->subscriptionBaseUpdate($locked, $status, $callbackPayload);

                if ($currentStatus !== $nextStatus && ! PaymentTransitions::canTransition($currentStatus, $nextStatus)) {
                    $locked->forceFill($baseUpdate)->save();

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

                    return $locked->fresh();
                }

                $locked->forceFill(array_filter(array_merge($baseUpdate, [
                    'status' => $nextStatus,
                    'paid_at' => $nextStatus === PaymentStatus::PAID ? ($locked->paid_at ?? $status->lastPaymentAt ?? now()) : $locked->paid_at,
                    'canceled_at' => $nextStatus === PaymentStatus::CANCELED ? ($locked->canceled_at ?? now()) : $locked->canceled_at,
                    'expired_at' => $nextStatus === PaymentStatus::EXPIRED ? ($locked->expired_at ?? now()) : $locked->expired_at,
                ]), static fn (mixed $value) => $value !== null))->save();

                $this->events->record($locked, [
                    'event_type' => 'provider_status_checked',
                    'source' => $source,
                    'before_status' => $currentStatus->value,
                    'after_status' => $locked->status->value,
                    'payload' => $status->raw,
                    'meta' => [
                        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                        'callback_present' => $callbackPayload !== null,
                    ],
                ]);

                $shouldDispatch = $locked->status === PaymentStatus::PAID && $locked->fulfilled_at === null;

                return $locked->fresh();
            });

            if ($shouldDispatch) {
                event(new PaymentConfirmed((int) $payment->id));
            }

            $this->lifecycle->handle($payment);

            return $payment->fresh();
        }

        $status = $this->oneTime->getStatus($payment);
        $nextStatus = $this->paymentMapper->toLocalStatus($status);

        $payment = DB::transaction(function () use ($payment, $status, $nextStatus, $source, $callbackPayload, &$shouldDispatch) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $currentStatus = $locked->status ?? PaymentStatus::PENDING;
            $baseUpdate = $this->paymentBaseUpdate($status, $callbackPayload);

            if ($currentStatus !== $nextStatus && ! PaymentTransitions::canTransition($currentStatus, $nextStatus)) {
                $locked->forceFill($baseUpdate)->save();

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

                return $locked->fresh();
            }

            $locked->forceFill(array_filter(array_merge($baseUpdate, [
                'status' => $nextStatus,
                'paid_at' => $nextStatus === PaymentStatus::PAID ? ($locked->paid_at ?? $status->paidAt ?? now()) : $locked->paid_at,
                'canceled_at' => $nextStatus === PaymentStatus::CANCELED ? ($locked->canceled_at ?? now()) : $locked->canceled_at,
                'expired_at' => $nextStatus === PaymentStatus::EXPIRED ? ($locked->expired_at ?? $status->declinedAt ?? now()) : $locked->expired_at,
            ]), static fn (mixed $value) => $value !== null))->save();

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

            $shouldDispatch = $locked->status === PaymentStatus::PAID && $locked->fulfilled_at === null;

            return $locked->fresh();
        });

        if ($shouldDispatch) {
            event(new PaymentConfirmed((int) $payment->id));
        }

        return $payment->fresh();
    }

    public function handleByFibPaymentId(string $fibPaymentId, string $source = 'callback', ?array $callbackPayload = null): ?Payment
    {
        $payment = Payment::query()
            ->where('provider_object_type', PaymentProviderObjectType::PAYMENT)
            ->where('fib_payment_id', $fibPaymentId)
            ->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        return $this->handle($payment, $source, $callbackPayload);
    }

    public function handleByFibSubscriptionId(string $fibSubscriptionId, string $source = 'callback', ?array $callbackPayload = null): ?Payment
    {
        $payment = Payment::query()
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->where('fib_subscription_id', $fibSubscriptionId)
            ->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        return $this->handle($payment, $source, $callbackPayload);
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
            'status_reason' => $status->decliningReason ?: $status->status,
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
            'status_reason' => $status->status,
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
}

<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibMapper;
use App\Domain\Payments\Fib\FibPaymentService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Domain\Payments\Support\PaymentTransitions;
use App\Events\Payments\PaymentConfirmed;
use Illuminate\Support\Facades\DB;

class ConfirmFibPayment
{
    public function __construct(
        protected FibPaymentService $fib,
        protected FibMapper $mapper,
        protected PaymentEventRecorder $events,
    ) {
    }

    public function handle(Payment $payment, string $source = 'manual_status_refresh', ?array $callbackPayload = null): Payment
    {
        $payment = $payment->fresh() ?? $payment;
        $status = $this->fib->getStatus($payment);
        $nextStatus = $this->mapper->toLocalStatus($status);
        $shouldDispatch = false;

        $payment = DB::transaction(function () use ($payment, $status, $nextStatus, $source, $callbackPayload, &$shouldDispatch) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $currentStatus = $locked->status ?? PaymentStatus::PENDING;

            $baseUpdate = [
                'provider_status' => $status->status,
                'declining_reason' => $this->mapper->normalizeDecliningReason($status->decliningReason),
                'status_reason' => $status->decliningReason ?: $status->status,
                'status_response' => $status->raw,
                'valid_until' => $status->validUntil,
                'last_status_checked_at' => now(),
            ];

            if ($callbackPayload !== null) {
                $baseUpdate['callback_payload'] = $callbackPayload;
                $baseUpdate['last_callback_received_at'] = now();
            }

            if ($currentStatus !== $nextStatus && ! PaymentTransitions::canTransition($currentStatus, $nextStatus)) {
                $locked->forceFill($baseUpdate)->save();

                $this->events->record($locked, [
                    'event_type' => 'payment_status_ignored',
                    'source' => $source,
                    'before_status' => $currentStatus->value,
                    'after_status' => $currentStatus->value,
                    'payload' => $status->raw,
                    'meta' => [
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
                'event_type' => 'payment_status_checked',
                'source' => $source,
                'before_status' => $currentStatus->value,
                'after_status' => $locked->status->value,
                'payload' => $status->raw,
                'meta' => [
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
        $payment = Payment::query()->where('fib_payment_id', $fibPaymentId)->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        return $this->handle($payment, $source, $callbackPayload);
    }
}

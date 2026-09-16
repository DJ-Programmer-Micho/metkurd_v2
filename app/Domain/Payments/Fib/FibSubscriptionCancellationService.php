<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Exceptions\FibApiException;
use App\Domain\Payments\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class FibSubscriptionCancellationService
{
    public function __construct(
        protected FibSubscriptionService $subscriptions,
    ) {}

    /**
     * @return array{
     *     result:string,
     *     provider_status:?string,
     *     active_until:?Carbon,
     *     last_payment_at:?Carbon,
     *     trace_id:?string,
     *     error_codes:array<int, string>
     * }
     */
    public function cancel(Payment $payment, bool $requestCancellation = true): array
    {
        try {
            $status = $this->subscriptions->getStatus($payment);
            if ($reason = app(\App\Domain\Payments\Support\FibStatusEvidence::class)->rejection($payment, $status)) {
                throw new FibApiException('Provider evidence rejected: '.$reason);
            }
        } catch (FibApiException $exception) {
            return [
                'result' => 'provider_error',
                'provider_status' => $this->subscriptions->normalizeProviderStatus($payment->providerStatusLabel()),
                'active_until' => $payment->active_until,
                'last_payment_at' => $payment->last_payment_at,
                'trace_id' => $exception->traceId(),
                'error_codes' => $exception->errorCodes(),
            ];
        }

        $providerStatus = $this->subscriptions->normalizeProviderStatus($status->status);
        $base = [
            'provider_status' => $providerStatus,
            'active_until' => $status->activeUntil,
            'last_payment_at' => $status->lastPaymentAt,
            'trace_id' => null,
            'error_codes' => [],
        ];

        if (! $this->subscriptions->isCancelableProviderStatus($providerStatus)) {
            return array_merge($base, [
                'result' => $this->resultForClosedStatus($providerStatus, $status->activeUntil, $status->lastPaymentAt),
            ]);
        }

        if (! $requestCancellation) {
            return array_merge($base, ['result' => 'awaiting_confirmation']);
        }

        try {
            $this->subscriptions->cancel($payment);
        } catch (FibApiException $exception) {
            if ($this->subscriptions->isCancelTransitionConflict($exception)) {
                return array_merge($base, [
                    'result' => $this->resultForClosedStatus($providerStatus, $status->activeUntil, $status->lastPaymentAt),
                    'trace_id' => $exception->traceId(),
                    'error_codes' => $exception->errorCodes(),
                ]);
            }

            return array_merge($base, [
                'result' => 'provider_error',
                'trace_id' => $exception->traceId(),
                'error_codes' => $exception->errorCodes(),
            ]);
        }

        return array_merge($base, [
            'result' => 'cancel_requested',
        ]);
    }

    protected function resultForClosedStatus(?string $providerStatus, ?Carbon $activeUntil, ?Carbon $lastPaymentAt): string
    {
        if (in_array($providerStatus, ['CANCELED', 'CANCELLED'], true) && $this->hasScheduledCancellationBoundary($activeUntil, $lastPaymentAt)) {
            return 'already_scheduled';
        }

        if (in_array($providerStatus, ['CANCELED', 'CANCELLED'], true)) {
            return 'already_canceled';
        }

        return 'non_cancelable';
    }

    protected function hasScheduledCancellationBoundary(?CarbonInterface $activeUntil, ?CarbonInterface $lastPaymentAt): bool
    {
        if (! $activeUntil instanceof CarbonInterface) {
            return false;
        }

        if ($lastPaymentAt instanceof CarbonInterface) {
            return $activeUntil->greaterThan($lastPaymentAt);
        }

        return $activeUntil->isFuture();
    }
}

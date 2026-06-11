<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use Carbon\CarbonInterface;

class CancelFibCheckout
{
    public function __construct(
        protected FibOneTimePaymentService $oneTime,
        protected FibSubscriptionService $subscriptions,
        protected FibSubscriptionCancellationService $subscriptionCancellation,
        protected SyncFibCheckoutStatus $sync,
        protected PaymentEventRecorder $events,
    ) {}

    public function handle(Payment $payment, string $source = 'manual_cancel'): Payment
    {
        $payment = $payment->fresh() ?? $payment;

        if ($payment->isTerminal() && $payment->status !== PaymentStatus::AWAITING_CUSTOMER_ACTION) {
            $payment->forceFill([
                'cancel_response' => $this->cancelResponse($payment, [
                    'accepted' => false,
                    'result' => $payment->isProviderSubscriptionObject() ? 'non_cancelable' : 'already_closed',
                    'source' => $source,
                ]),
            ])->save();

            return $payment;
        }

        if ($payment->isProviderSubscriptionObject()) {
            $providerCancellation = $this->subscriptionCancellation->cancel($payment);

            $payment->forceFill([
                'cancel_response' => $this->cancelResponse($payment, [
                    'accepted' => $providerCancellation['result'] !== 'provider_error',
                    'result' => $providerCancellation['result'],
                    'source' => $source,
                    'provider_status' => $providerCancellation['provider_status'],
                    'trace_id' => $providerCancellation['trace_id'],
                    'error_codes' => $providerCancellation['error_codes'],
                ]),
            ])->save();

            $this->events->record($payment, [
                'event_type' => $providerCancellation['result'] === 'provider_error'
                    ? 'provider_cancel_failed'
                    : ($providerCancellation['result'] === 'cancel_requested'
                        ? 'provider_cancel_requested'
                        : 'provider_cancel_skipped'),
                'source' => $source,
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'accepted' => $providerCancellation['result'] !== 'provider_error',
                    'result' => $providerCancellation['result'],
                    'provider_object_type' => ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->value,
                    'provider_status' => $providerCancellation['provider_status'],
                    'trace_id' => $providerCancellation['trace_id'],
                    'error_codes' => $providerCancellation['error_codes'],
                ],
            ]);

            if ($providerCancellation['result'] === 'provider_error') {
                return $payment->fresh();
            }
        } else {
            $this->oneTime->cancel($payment);

            $payment->forceFill([
                'cancel_response' => $this->cancelResponse($payment, [
                    'accepted' => true,
                    'result' => 'cancel_requested',
                    'source' => $source,
                ]),
            ])->save();

            $this->events->record($payment, [
                'event_type' => 'provider_cancel_requested',
                'source' => $source,
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'accepted' => true,
                    'provider_object_type' => ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->value,
                ],
            ]);
        }

        try {
            $synced = $this->sync->handle($payment, 'cancel_confirmation');

            if ($synced->isProviderSubscriptionObject()) {
                $synced->forceFill([
                    'cancel_response' => $this->cancelResponse($synced, [
                        'accepted' => (bool) data_get($synced->cancel_response, 'accepted', true),
                        'result' => $this->normalizedSubscriptionCancelResult(
                            $synced,
                            (string) data_get($synced->cancel_response, 'result', 'cancel_requested')
                        ),
                        'source' => (string) data_get($synced->cancel_response, 'source', $source),
                        'provider_status' => $synced->providerStatusLabel(),
                        'trace_id' => data_get($synced->cancel_response, 'trace_id'),
                        'error_codes' => data_get($synced->cancel_response, 'error_codes'),
                    ]),
                ])->save();
            }

            return $synced->fresh();
        } catch (\Throwable) {
            return $payment->fresh();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function cancelResponse(Payment $payment, array $data): array
    {
        return array_filter(array_merge([
            'provider_object_type' => ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->value,
            'accepted_at' => now()->toIso8601String(),
        ], $data), static fn (mixed $value) => $value !== null);
    }

    protected function normalizedSubscriptionCancelResult(Payment $payment, string $fallback): string
    {
        $providerStatus = $this->subscriptions->normalizeProviderStatus($payment->providerStatusLabel());

        if (in_array($providerStatus, ['CANCELED', 'CANCELLED'], true)
            && $this->hasScheduledCancellationBoundary($payment->active_until, $payment->last_payment_at)) {
            return 'already_scheduled';
        }

        if (in_array($providerStatus, ['CANCELED', 'CANCELLED'], true)) {
            return 'already_canceled';
        }

        if ($payment->active_until?->isFuture() && $fallback === 'cancel_requested') {
            return 'cancel_requested';
        }

        return $fallback;
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

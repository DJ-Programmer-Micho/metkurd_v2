<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\CancelFibPayment;
use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class FibPaymentController extends Controller
{
    public function status(string $locale, Payment $payment, ConfirmFibPayment $confirm): JsonResponse
    {
        $this->authorize('view', $payment);

        $payment = $payment->fresh(['customer.profile']) ?? $payment;

        if ($this->shouldPollProvider($payment)) {
            try {
                $payment = $confirm->handle($payment, 'frontend_status_poll');
            } catch (\Throwable $exception) {
                Log::warning('FIB checkout status poll failed.', [
                    'payment_id' => $payment->id,
                    'payment_uuid' => (string) $payment->uuid,
                    'provider_reference' => $payment->providerReference(),
                    'source' => 'frontend_status_poll',
                    'message' => $exception->getMessage(),
                ]);

                $payment = $payment->fresh(['customer.profile']) ?? $payment;
            }
        }

        $payment = $payment->fresh(['customer.profile']) ?? $payment;

        $isSuccess = $payment->isPaid();
        $isTerminal = $payment->isTerminal();
        $state = $this->frontendState($payment);
        $message = $isSuccess
            ? $this->successMessage($payment)
            : $this->statusMessage($payment);

        return response()->json([
            'status' => $payment->status->value,
            'state' => $state,
            'is_terminal' => $isTerminal,
            'is_success' => $isSuccess,
            'can_retry' => in_array($payment->status->value, ['failed', 'canceled', 'expired'], true),
            'redirect_url' => $isSuccess && $payment->fulfilled_at !== null
                ? route('app.home', ['locale' => $locale])
                : null,
            'message' => $message,
            'provider_status' => $payment->providerStatusLabel(),
            'checked_at' => optional($payment->last_status_checked_at)->toIso8601String(),
        ]);
    }

    public function refresh(string $locale, Payment $payment, ConfirmFibPayment $confirm): RedirectResponse
    {
        $this->authorize('update', $payment);

        $payment = $confirm->handle($payment, 'manual_status_refresh');

        return redirect()->route('payments.fib.show', [
            'locale' => app()->getLocale(),
            'payment' => $payment,
        ])->with('payment_status_message', $this->statusMessage($payment));
    }

    public function cancel(string $locale, Payment $payment, CancelFibPayment $cancel): RedirectResponse
    {
        $this->authorize('update', $payment);

        try {
            $payment = $cancel->handle($payment, 'manual_cancel');
        } catch (\Throwable) {
            $payment = $payment->fresh() ?? $payment;

            return redirect()->route('payments.fib.show', [
                'locale' => app()->getLocale(),
                'payment' => $payment,
            ])->with('payment_status_message', __('We could not confirm the cancellation right now. Please refresh the status or contact support if needed.'));
        }

        return redirect()->route('payments.fib.show', [
            'locale' => app()->getLocale(),
            'payment' => $payment,
        ])->with('payment_status_message', $this->cancelMessage($payment));
    }

    public function thankYou(string $locale, Payment $payment): RedirectResponse
    {
        $this->authorize('view', $payment);

        return redirect()->route('payments.fib.show', [
            'locale' => app()->getLocale(),
            'payment' => $payment,
        ])->with('payment_status_message', $payment->isPaid()
            ? $this->successMessage($payment)
            : $this->statusMessage($payment));
    }

    protected function statusMessage(Payment $payment): string
    {
        $object = $payment->isProviderSubscriptionObject() ? __('subscription checkout') : __('payment');

        return match ($payment->status->value) {
            'paid' => $this->successMessage($payment),
            'failed' => __('Your FIB :object was declined.', ['object' => $object]),
            'canceled' => __('Your FIB :object was canceled before completion.', ['object' => $object]),
            'expired' => __('This FIB :object expired. Please start a new checkout.', ['object' => $object]),
            default => __('Your FIB :object is still waiting to be completed.', ['object' => $object]),
        };
    }

    protected function cancelMessage(Payment $payment): string
    {
        $object = $payment->isProviderSubscriptionObject() ? __('subscription checkout') : __('payment');
        $cancelResult = (string) data_get($payment->cancel_response, 'result', '');
        $activeUntil = $payment->active_until?->timezone(config('app.timezone'))->format('Y-m-d H:i');

        if ($cancelResult === 'already_scheduled') {
            return __('Cancellation has already been scheduled. Your subscription stays active until :date.', [
                'date' => $activeUntil ?: __('the current renewal boundary'),
            ]);
        }

        if ($cancelResult === 'already_canceled') {
            return __('This subscription is already canceled.');
        }

        if ($cancelResult === 'non_cancelable') {
            return __('This subscription can no longer be canceled from checkout.');
        }

        if ($cancelResult === 'provider_error') {
            return __('We could not confirm the cancellation right now. Please refresh the status or contact support if needed.');
        }

        return $payment->status->value === 'canceled'
            ? __('Your FIB :object was canceled before completion.', ['object' => $object])
            : ($payment->isProviderSubscriptionObject() && $payment->active_until?->isFuture()
                ? __('Cancellation was requested. Your subscription stays active until :date.', [
                    'date' => $activeUntil ?: __('the current renewal boundary'),
                ])
                : __('Cancel was requested for this FIB :object. Refresh the status if the provider has not confirmed the cancellation yet.', ['object' => $object]));
    }

    protected function successMessage(Payment $payment): string
    {
        return $payment->isProviderSubscriptionObject()
            ? __('Congrats! Your subscription was confirmed successfully. We sent the confirmation by email.')
            : __('Congrats! Your payment was confirmed successfully. We sent the confirmation by email.');
    }

    protected function shouldPollProvider(Payment $payment): bool
    {
        if ($payment->isTerminal()) {
            return false;
        }

        return ! $payment->last_status_checked_at?->greaterThan(now()->subSeconds(4));
    }

    protected function frontendState(Payment $payment): string
    {
        return match ($payment->status->value) {
            'paid' => 'success',
            'failed' => 'failed',
            'canceled' => 'canceled',
            'expired' => 'expired',
            default => 'pending',
        };
    }
}

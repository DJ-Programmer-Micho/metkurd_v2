<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\CancelFibPayment;
use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class FibPaymentController extends Controller
{
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

        $payment = $cancel->handle($payment, 'manual_cancel');

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

        return $payment->status->value === 'canceled'
            ? __('Your FIB :object was canceled before completion.', ['object' => $object])
            : __('Cancel was requested for this FIB :object. Refresh the status if the provider has not confirmed the cancellation yet.', ['object' => $object]);
    }

    protected function successMessage(Payment $payment): string
    {
        return $payment->isProviderSubscriptionObject()
            ? __('Congrats! Your subscription was confirmed successfully. We sent the confirmation by email.')
            : __('Congrats! Your payment was confirmed successfully. We sent the confirmation by email.');
    }
}

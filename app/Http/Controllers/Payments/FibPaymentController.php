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
            ? __('Congrats! Your payment was confirmed successfully. We sent the confirmation by email.')
            : $this->statusMessage($payment));
    }

    protected function statusMessage(Payment $payment): string
    {
        return match ($payment->status->value) {
            'paid' => __('Congrats! Your payment was confirmed successfully. We sent the confirmation by email.'),
            'failed' => __('Payment was declined by FIB.'),
            'canceled' => __('Payment was canceled before completion.'),
            'expired' => __('This FIB payment expired. Please start a new checkout.'),
            default => __('Payment is still waiting to be completed.'),
        };
    }

    protected function cancelMessage(Payment $payment): string
    {
        return $payment->status->value === 'canceled'
            ? __('Payment was canceled before completion.')
            : __('Cancel was requested. Refresh the status if the provider has not confirmed the cancellation yet.');
    }
}

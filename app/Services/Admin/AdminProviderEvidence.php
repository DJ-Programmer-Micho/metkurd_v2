<?php

namespace App\Services\Admin;

use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use Illuminate\Validation\ValidationException;

class AdminProviderEvidence
{
    public function verify(Payment $payment, string $candidate): FibPaymentStatusData|FibSubscriptionStatusData
    {
        if ($payment->provider !== PaymentProvider::FIB || trim($candidate) === '' || ! $payment->customer || ! $payment->purchasable) {
            $this->reject();
        }
        try {
            $status = $payment->isProviderSubscriptionObject()
                ? app(FibSubscriptionService::class)->getStatusBySubscriptionId($candidate)
                : app(FibOneTimePaymentService::class)->getStatusByPaymentId($candidate);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['providerReference' => __('admin_p0.provider_failed')]);
        }
        $returnedId = $status instanceof FibSubscriptionStatusData ? $status->subscriptionId : $status->paymentId;
        $money = $status->raw['monetaryValue'] ?? $status->raw['amount'] ?? null;
        if ($returnedId !== $candidate || ! is_array($money) || ! is_numeric($money['amount'] ?? null)
            || (float) $money['amount'] !== (float) $payment->amount
            || strtoupper((string) ($money['currency'] ?? '')) !== strtoupper($payment->currency)) {
            $this->reject();
        }
        if ($status instanceof FibSubscriptionStatusData) {
            if (! in_array($status->status, ['ACTIVE', 'SUBSCRIBED', 'PAID'], true)
                || ! app(FibSubscriptionMapper::class)->hasConfirmedPaymentEvidence($status)) {
                $this->reject();
            }
        } elseif (! in_array($status->status, ['PAID', 'APPROVED', 'CAPTURED', 'SETTLED', 'SUCCESS'], true)) {
            $this->reject();
        }
        $column = $payment->isProviderSubscriptionObject() ? 'fib_subscription_id' : 'fib_payment_id';
        if (Payment::where($column, $candidate)->whereKeyNot($payment->id)->exists()) {
            $this->reject();
        }
        // A different object's paid status/amount alone cannot prove customer ownership.
        if ((string) $payment->{$column} !== $candidate) {
            $reference = trim((string) $payment->local_reference);
            $description = (string) ($status->raw['description'] ?? '');
            $reportedReference = (string) ($status->raw['localReference'] ?? $status->raw['merchantTransactionId'] ?? '');
            $matches = $reference !== '' && ($reportedReference === $reference
                || preg_match('/(?<![A-Za-z0-9_-])'.preg_quote($reference, '/').'(?![A-Za-z0-9_-])/', $description));
            if (! $matches) {
                $this->reject();
            }
        }

        return $status;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['providerReference' => __('admin_p0.provider_mismatch')]);
    }
}

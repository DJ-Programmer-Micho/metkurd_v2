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
    /** Local evidence only: never contact the provider to clear abandoned history. */
    public function preventsCheckoutInvalidation(Payment $payment): bool
    {
        foreach ([$payment->provider_status, $payment->provider_payment_status, $payment->provider_subscription_status] as $status) {
            if (! in_array(strtoupper(trim((string) $status)), ['', 'DRAFT', 'UNPAID', 'PENDING', 'CREATED', 'INITIATED', 'FAILED', 'DECLINED', 'REJECTED', 'CANCELED', 'CANCELLED', 'EXPIRED', 'TIMED_OUT'], true)) {
                return true;
            }
        }
        foreach ([$payment->create_response, $payment->status_response, $payment->cancel_response, $payment->callback_payload, $payment->meta] as $payload) {
            if ($this->containsCollectionEvidence((array) $payload)) {
                return true;
            }
        }
        // Older observations must not disappear behind a newer DRAFT response.
        foreach ($payment->events()->lazyById(100) as $event) {
            if ($this->containsCollectionEvidence([
                'before_status' => $event->before_status, 'after_status' => $event->after_status,
                'payload' => $event->payload, 'meta' => $event->meta,
            ])) {
                return true;
            }
        }

        return false;
    }

    private function containsCollectionEvidence(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                if ($this->containsCollectionEvidence($value)) {
                    return true;
                }

                continue;
            }
            $key = strtolower(str_replace('_', '', (string) $key));
            if (str_ends_with($key, 'status') && is_scalar($value)
                && in_array(strtoupper(trim((string) $value)), ['PAID', 'APPROVED', 'CONFIRMED', 'CAPTURED', 'SETTLED', 'SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'ACTIVE', 'SUBSCRIBED', 'REFUNDED', 'REFUND_REQUESTED', 'APPLIED', 'PAID_PENDING_APPLICATION'], true)) {
                return true;
            }
            if (in_array($key, ['paidat', 'lastpaymentat', 'activeuntil', 'fulfilledat'], true) && $value !== null && $value !== '') {
                return true;
            }
            if (in_array($key, ['paid', 'ispaid', 'paymentcompleted', 'ispaymentcompleted'], true)
                && in_array($value, [true, 1, '1', 'true'], true)) {
                return true;
            }
        }

        return false;
    }

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
        // Reference correction has its own ownership proof below. Apply shared
        // format/money checks against the candidate without changing the real row.
        $candidatePayment = clone $payment;
        $candidatePayment->setAttribute($status instanceof FibSubscriptionStatusData ? 'fib_subscription_id' : 'fib_payment_id', $candidate);
        if (app(\App\Domain\Payments\Support\FibStatusEvidence::class)->rejection($candidatePayment, $status) !== null) {
            $this->reject();
        }
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

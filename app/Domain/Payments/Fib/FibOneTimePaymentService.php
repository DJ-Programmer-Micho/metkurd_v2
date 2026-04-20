<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibCreatePaymentRequestData;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Str;

class FibOneTimePaymentService
{
    public function __construct(
        protected FibOneTimePaymentClient $client,
        protected FibCallbackUrlService $callbackUrls,
    ) {
    }

    public function createPayment(Payment $payment, string $redirectUri): array
    {
        $request = new FibCreatePaymentRequestData(
            amount: (int) round((float) $payment->amount),
            currency: (string) $payment->currency,
            statusCallbackUrl: $this->callbackUrl(),
            description: $this->description($payment),
            redirectUri: $redirectUri,
            expiresIn: config('fib.payment.expires_in'),
            category: config('fib.payment.category'),
            refundableFor: config('fib.payment.refundable_for'),
        );

        $response = $this->client->createPayment($request);

        return [
            'request' => $request,
            'response' => $response,
        ];
    }

    public function getStatus(Payment $payment): FibPaymentStatusData
    {
        return $this->client->getPaymentStatus((string) $payment->fib_payment_id);
    }

    public function cancel(Payment $payment): void
    {
        $this->client->cancelPayment((string) $payment->fib_payment_id);
    }

    protected function description(Payment $payment): string
    {
        $snapshot = $payment->snapshot();
        $name = trim((string) ($snapshot['name'] ?? 'Payment'));
        $reference = trim((string) $payment->local_reference);
        $base = trim(Str::limit("MET KURD {$name} {$reference}", 50, ''));

        return $base !== '' ? $base : Str::limit($reference, 50, '');
    }

    protected function callbackUrl(): string
    {
        return $this->callbackUrls->absoluteRoute('payments.fib.callback');
    }
}

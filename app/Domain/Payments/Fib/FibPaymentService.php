<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibCreatePaymentRequestData;
use App\Domain\Payments\Data\FibCreatePaymentResponseData;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Str;

class FibPaymentService
{
    public function __construct(
        protected FibClient $client,
    ) {
    }

    public function createPayment(Payment $payment, string $redirectUri): array
    {
        $request = new FibCreatePaymentRequestData(
            amount: (int) round((float) $payment->amount),
            currency: (string) $payment->currency,
            statusCallbackUrl: route('payments.fib.callback'),
            description: $this->description($payment),
            redirectUri: $redirectUri,
            expiresIn: config('services.fib.payment.expires_in'),
            category: config('services.fib.payment.category'),
            refundableFor: config('services.fib.payment.refundable_for'),
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
}

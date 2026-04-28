<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibCreatePaymentRequestData;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FibOneTimePaymentService
{
    public function __construct(
        protected FibOneTimePaymentClient $client,
        protected FibCallbackUrlService $callbackUrls,
        protected FibConfiguration $config,
    ) {
    }

    public function createPayment(Payment $payment, string $redirectUri): array
    {
        $callbackUrl = $this->callbackUrl();
        $this->callbackUrls->ensurePublicUrl($callbackUrl, 'payment');

        $request = new FibCreatePaymentRequestData(
            amount: (int) round((float) $payment->amount),
            currency: (string) $payment->currency,
            statusCallbackUrl: $callbackUrl,
            description: $this->description($payment),
            redirectUri: $redirectUri,
            expiresIn: config('fib.payment.expires_in'),
            category: config('fib.payment.category'),
            refundableFor: config('fib.payment.refundable_for'),
        );

        Log::info('FIB checkout create request prepared.', [
            'provider_object_type' => 'payment',
            'payment_id' => $payment->id,
            'payment_uuid' => (string) $payment->uuid,
            'customer_id' => (int) $payment->customer_id,
            'provider_reference' => $payment->providerReference(),
            'fib_environment' => $this->config->environment(),
            'profile' => 'payment',
            'base_url_host' => parse_url($this->config->baseUrl('payment'), PHP_URL_HOST) ?: null,
            'token_url_host' => parse_url($this->config->url('payment', 'token'), PHP_URL_HOST) ?: null,
            'status_callback_url' => $callbackUrl,
            'redirect_uri_host' => parse_url($redirectUri, PHP_URL_HOST) ?: null,
            'vm_hostname' => gethostname() ?: php_uname('n'),
        ]);

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

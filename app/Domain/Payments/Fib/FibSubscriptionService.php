<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibCreateSubscriptionRequestData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Str;

class FibSubscriptionService
{
    public function __construct(
        protected FibSubscriptionClient $client,
        protected FibCallbackUrlService $callbackUrls,
    ) {
    }

    public function createSubscription(Payment $payment): array
    {
        $request = new FibCreateSubscriptionRequestData(
            title: $this->title($payment),
            description: $this->description($payment),
            amount: (int) round((float) $payment->amount),
            currency: (string) $payment->currency,
            interval: $this->interval($payment),
            trialPeriod: $this->trialPeriod($payment),
            expiresIn: config('fib.subscription.expires_in'),
            statusCallbackUrl: $this->callbackUrl(),
        );

        $response = $this->client->createSubscription($request);

        return [
            'request' => $request,
            'response' => $response,
        ];
    }

    public function getStatus(Payment $payment): FibSubscriptionStatusData
    {
        return $this->client->getSubscription((string) $payment->fib_subscription_id);
    }

    public function cancel(Payment $payment): void
    {
        $this->client->cancelSubscription((string) $payment->fib_subscription_id);
    }

    public function intervalForCycle(string $billingCycle): string
    {
        $normalized = strtolower(trim($billingCycle));

        return (string) config(
            'fib.subscription.intervals.' . ($normalized === 'yearly' ? 'yearly' : 'monthly'),
            $normalized === 'yearly' ? 'P1Y' : 'P1M'
        );
    }

    protected function title(Payment $payment): string
    {
        $snapshot = $payment->snapshot();
        $name = trim((string) ($snapshot['name'] ?? 'Subscription'));

        return Str::limit("MET KURD {$name}", 50, '');
    }

    protected function description(Payment $payment): string
    {
        $snapshot = $payment->snapshot();
        $name = trim((string) ($snapshot['name'] ?? 'Subscription'));
        $reference = trim((string) $payment->local_reference);
        $base = trim(Str::limit("MET KURD {$name} {$reference}", 50, ''));

        return $base !== '' ? $base : Str::limit($reference, 50, '');
    }

    protected function interval(Payment $payment): string
    {
        $snapshot = $payment->snapshot();
        $billingCycle = (string) ($snapshot['billing_cycle'] ?? 'monthly');

        return $this->intervalForCycle($billingCycle);
    }

    protected function trialPeriod(Payment $payment): ?string
    {
        $trialPeriod = data_get($payment->purchase_snapshot, 'trial_period');

        if (is_string($trialPeriod) && trim($trialPeriod) !== '') {
            return trim($trialPeriod);
        }

        $configured = config('fib.subscription.trial_period');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }

    protected function callbackUrl(): string
    {
        $url = $this->callbackUrls->absoluteRoute('payments.fib.subscription.callback');
        $this->callbackUrls->ensurePublicUrl($url, 'subscription');

        return $url;
    }
}

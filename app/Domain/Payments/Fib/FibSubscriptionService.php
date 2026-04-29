<?php

namespace App\Domain\Payments\Fib;

use App\Domain\Payments\Data\FibCreateSubscriptionRequestData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FibSubscriptionService
{
    public function __construct(
        protected FibSubscriptionClient $client,
        protected FibCallbackUrlService $callbackUrls,
        protected FibConfiguration $config,
    ) {
    }

    public function createSubscription(Payment $payment): array
    {
        $callbackUrl = $this->callbackUrl();

        $request = new FibCreateSubscriptionRequestData(
            title: $this->title($payment),
            description: $this->description($payment),
            amount: (int) round((float) $payment->amount),
            currency: (string) $payment->currency,
            interval: $this->interval($payment),
            trialPeriod: $this->trialPeriod($payment),
            expiresIn: config('fib.subscription.expires_in'),
            statusCallbackUrl: $callbackUrl,
        );

        // Log::info('FIB subscription checkout create request prepared.', [
        //     'provider_object_type' => 'subscription',
        //     'payment_id' => $payment->id,
        //     'payment_uuid' => (string) $payment->uuid,
        //     'customer_id' => (int) $payment->customer_id,
        //     'provider_reference' => $payment->providerReference(),
        //     'fib_environment' => $this->config->environment(),
        //     'profile' => 'subscription',
        //     'base_url_host' => parse_url($this->config->baseUrl('subscription'), PHP_URL_HOST) ?: null,
        //     'token_url_host' => parse_url($this->config->url('subscription', 'token'), PHP_URL_HOST) ?: null,
        //     'status_callback_url' => $callbackUrl,
        //     'provider_interval' => $request->interval,
        //     'vm_hostname' => gethostname() ?: php_uname('n'),
        // ]);

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

    public function hourlyTestingEnabled(): bool
    {
        if (!(bool) config('fib.subscription.hourly_testing_enabled', false)) {
            return false;
        }

        if ((bool) config('payments.fake_enabled', false)) {
            return true;
        }

        return app()->environment(['local', 'testing']);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public function normalizeBillingCycle(string $billingCycle, array $allowed = ['monthly', 'yearly', 'hourly']): string
    {
        $billingCycle = strtolower(trim($billingCycle));
        $supported = array_values(array_intersect($allowed, $this->supportedBillingCycles()));

        if ($supported === []) {
            $supported = ['monthly'];
        }

        return in_array($billingCycle, $supported, true)
            ? $billingCycle
            : $supported[0];
    }

    /**
     * @return array<int, string>
     */
    public function supportedBillingCycles(): array
    {
        $cycles = ['monthly', 'yearly'];

        if ($this->hourlyTestingEnabled()) {
            $cycles[] = 'hourly';
        }

        return $cycles;
    }

    public function intervalForCycle(string $billingCycle): string
    {
        $normalized = $this->normalizeBillingCycle($billingCycle);
        $intervalKey = match ($normalized) {
            'yearly' => 'yearly',
            'hourly' => 'hourly',
            default => 'monthly',
        };

        return (string) config(
            'fib.subscription.intervals.' . $intervalKey,
            match ($intervalKey) {
                'yearly' => 'P1Y',
                'hourly' => 'PT1H',
                default => 'P1M',
            }
        );
    }

    public function normalizeProviderStatus(?string $status): ?string
    {
        $status = is_string($status) ? strtoupper(trim($status)) : '';

        return $status !== '' ? $status : null;
    }

    public function isCancelableProviderStatus(?string $status): bool
    {
        $normalized = $this->normalizeProviderStatus($status);

        return in_array($normalized, [
            'ACTIVE',
            'PAID',
            'SUBSCRIBED',
            'UNPAID',
            'PENDING',
            'CREATED',
            'INITIATED',
        ], true);
    }

    public function isClosedProviderStatus(?string $status): bool
    {
        $normalized = $this->normalizeProviderStatus($status);

        return in_array($normalized, [
            'CANCELED',
            'CANCELLED',
            'EXPIRED',
            'TIMED_OUT',
            'DECLINED',
            'REJECTED',
            'FAILED',
            'INACTIVE',
            'ENDED',
        ], true);
    }

    public function isCancelTransitionConflict(\Throwable $exception): bool
    {
        if ($exception instanceof \App\Domain\Payments\Exceptions\FibApiException) {
            return $exception->hasErrorCode('ILLEGAL_SUBSCRIPTION_STATUS_TRANSITION');
        }

        return str_contains(strtoupper($exception->getMessage()), 'ILLEGAL_SUBSCRIPTION_STATUS_TRANSITION');
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
        $billingCycle = $this->normalizeBillingCycle((string) ($snapshot['billing_cycle'] ?? 'monthly'));

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

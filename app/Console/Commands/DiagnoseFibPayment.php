<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Fib\FibCallbackUrlService;
use App\Domain\Payments\Fib\FibConfiguration;
use App\Domain\Payments\Fib\FibMapper;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibStatusReasonParser;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use Illuminate\Console\Command;

class DiagnoseFibPayment extends Command
{
    protected $signature = 'payments:fib:diagnose
        {payment_uuid? : Optional payment UUID to inspect}
        {--no-provider-check : Skip the live provider status lookup}';

    protected $description = 'Diagnose FIB runtime config and inspect one payment against live provider status.';

    public function __construct(
        protected FibConfiguration $config,
        protected FibCallbackUrlService $callbackUrls,
        protected FibOneTimePaymentService $payments,
        protected FibSubscriptionService $subscriptions,
        protected FibMapper $paymentMapper,
        protected FibSubscriptionMapper $subscriptionMapper,
        protected FibStatusReasonParser $reasonParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->renderRuntimeContext();
        $this->renderProfileContext('payment');
        $this->renderProfileContext('subscription');

        $uuid = trim((string) $this->argument('payment_uuid'));

        if ($uuid === '') {
            $this->newLine();
            $this->comment('Pass a payment UUID to inspect a specific checkout state.');

            return self::SUCCESS;
        }

        $payment = Payment::query()->where('uuid', $uuid)->first();

        if (! $payment instanceof Payment) {
            $this->error("Payment [{$uuid}] was not found.");

            return self::FAILURE;
        }

        $this->renderPaymentContext($payment);

        if (! $this->option('no-provider-check')) {
            $this->renderProviderCheck($payment);
        }

        return self::SUCCESS;
    }

    protected function renderRuntimeContext(): void
    {
        $this->newLine();
        $this->info('RUNTIME CONTEXT');
        $this->table(['Key', 'Value'], [
            ['app_env', (string) config('app.env')],
            ['app_url', (string) config('app.url')],
            ['fib_environment', $this->config->environment()],
            ['callback_base_url', (string) config('fib.callback_base_url', config('app.url'))],
            ['config_cached', app()->configurationIsCached() ? 'yes' : 'no'],
            ['vm_hostname', gethostname() ?: php_uname('n')],
        ]);
    }

    protected function renderProfileContext(string $profile): void
    {
        $summary = $this->config->debugSummary($profile);
        $routeName = $profile === 'subscription'
            ? 'payments.fib.subscription.callback'
            : 'payments.fib.callback';
        $callbackUrl = $this->callbackUrls->absoluteRoute($routeName);
        $callbackHealth = $this->callbackHealth($callbackUrl, $profile);

        $this->newLine();
        $this->info(strtoupper($profile).' PROFILE');
        $this->table(['Key', 'Value'], [
            ['base_url_host', parse_url((string) $summary['base_url'], PHP_URL_HOST) ?: 'n/a'],
            ['token_url_host', parse_url((string) $summary['token_url'], PHP_URL_HOST) ?: 'n/a'],
            ['base_url_source', (string) ($summary['base_url_source'] ?? 'n/a')],
            ['client_id', (string) ($summary['client_id'] ?? 'n/a')],
            ['client_id_source', (string) ($summary['client_id_source'] ?? 'n/a')],
            ['client_secret_present', (bool) ($summary['client_secret_present'] ?? false) ? 'yes' : 'no'],
            ['callback_url', $callbackUrl],
            ['callback_url_health', $callbackHealth],
        ]);
    }

    protected function callbackHealth(string $url, string $profile): string
    {
        try {
            $this->callbackUrls->ensurePublicUrl($url, $profile);

            return 'ok';
        } catch (\Throwable $exception) {
            return 'invalid: '.$exception->getMessage();
        }
    }

    protected function renderPaymentContext(Payment $payment): void
    {
        $this->newLine();
        $this->info('PAYMENT RECORD');
        $this->table(['Key', 'Value'], [
            ['payment_id', (string) $payment->id],
            ['payment_uuid', (string) $payment->uuid],
            ['customer_id', (string) $payment->customer_id],
            ['provider', (string) ($payment->provider?->value ?? $payment->provider)],
            ['provider_object_type', (string) ($payment->provider_object_type?->value ?? 'payment')],
            ['payment_mode', (string) ($payment->payment_mode?->value ?? $payment->payment_mode)],
            ['purchase_type', (string) ($payment->purchase_type?->value ?? $payment->purchase_type)],
            ['local_status', (string) ($payment->status?->value ?? $payment->status)],
            ['provider_status_local', (string) ($payment->providerStatusLabel() ?? '')],
            ['status_reason', (string) ($payment->status_reason ?? '')],
            ['declining_reason', (string) ($payment->declining_reason ?? '')],
            ['provider_reference', $payment->providerReference()],
            ['fib_payment_id', (string) ($payment->fib_payment_id ?? '')],
            ['fib_subscription_id', (string) ($payment->fib_subscription_id ?? '')],
            ['created_at', $this->dateLabel($payment->created_at)],
            ['updated_at', $this->dateLabel($payment->updated_at)],
            ['last_callback_received_at', $this->dateLabel($payment->last_callback_received_at)],
            ['last_status_checked_at', $this->dateLabel($payment->last_status_checked_at)],
            ['paid_at', $this->dateLabel($payment->paid_at)],
            ['active_until', $this->dateLabel($payment->active_until)],
        ]);
    }

    protected function renderProviderCheck(Payment $payment): void
    {
        $this->newLine();
        $this->info('LIVE PROVIDER STATUS');

        try {
            if (($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->isSubscription()) {
                $this->renderLiveSubscriptionStatus($payment);

                return;
            }

            $this->renderLivePaymentStatus($payment);
        } catch (\Throwable $exception) {
            $this->error('Provider lookup failed: '.$exception->getMessage());
        }
    }

    protected function renderLivePaymentStatus(Payment $payment): void
    {
        if (! filled($payment->fib_payment_id)) {
            $this->warn('Cannot query provider status: fib_payment_id is empty.');

            return;
        }

        $status = $this->payments->getStatus($payment);
        $mapped = $this->paymentMapper->toLocalStatus($status);
        $providerReason = $this->reasonParser->reasonFromRaw($status->raw) ?? $status->decliningReason;
        $errorCodes = $this->reasonParser->errorCodesFromRaw($status->raw);

        $this->table(['Key', 'Value'], [
            ['provider_payment_id', $status->paymentId],
            ['provider_status_raw', $status->status],
            ['mapped_local_status', $mapped->value],
            ['provider_reason', $providerReason ?? 'n/a'],
            ['provider_error_codes', $errorCodes !== [] ? implode(', ', $errorCodes) : 'n/a'],
            ['valid_until', $this->dateLabel($status->validUntil)],
            ['paid_at', $this->dateLabel($status->paidAt)],
            ['declined_at', $this->dateLabel($status->declinedAt)],
        ]);
    }

    protected function renderLiveSubscriptionStatus(Payment $payment): void
    {
        if (! filled($payment->fib_subscription_id)) {
            $this->warn('Cannot query provider status: fib_subscription_id is empty.');

            return;
        }

        $status = $this->subscriptions->getStatus($payment);
        $mapped = $this->subscriptionMapper->toLocalStatus($status);
        $providerReason = $this->reasonParser->reasonFromRaw($status->raw);
        $errorCodes = $this->reasonParser->errorCodesFromRaw($status->raw);

        $this->table(['Key', 'Value'], [
            ['provider_subscription_id', $status->subscriptionId],
            ['provider_status_raw', $status->status],
            ['mapped_local_status', $mapped->value],
            ['provider_reason', $providerReason ?? 'n/a'],
            ['provider_error_codes', $errorCodes !== [] ? implode(', ', $errorCodes) : 'n/a'],
            ['interval', $status->interval ?? 'n/a'],
            ['valid_until', $this->dateLabel($status->validUntil)],
            ['active_until', $this->dateLabel($status->activeUntil)],
            ['last_payment_at', $this->dateLabel($status->lastPaymentAt)],
        ]);
    }

    protected function dateLabel(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format(DATE_ATOM)
            : 'n/a';
    }
}

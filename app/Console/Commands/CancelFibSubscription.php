<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\CustomerServiceSubscription;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CancelFibSubscription extends Command
{
    protected ?string $validationError = null;

    protected $signature = 'payments:fib:cancel-subscription
        {payment : Local payment id to cancel at provider side}
        {--customer= : Expected customer id}
        {--reason=admin_superseded_by_new_subscription : Operator reason for the cancellation}
        {--superseded-by= : Newer local payment id that supersedes this one}
        {--force-current : Allow canceling the current latest active-looking subscription}
        {--dry-run : Preview only (default unless --execute is provided)}
        {--execute : Perform the provider cancellation and local mutation}';

    protected $description = 'Safely cancel a superseded FIB recurring service-plan subscription. Dry-run by default.';

    public function __construct(
        protected FibSubscriptionService $subscriptions,
        protected FibSubscriptionCancellationService $cancellation,
        protected PaymentEventRecorder $events,
        protected TelegramSubscriptionLifecycleNotifier $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find((int) $this->argument('payment'));

        if (! $payment instanceof Payment) {
            $this->error('The requested payment was not found.');

            return self::FAILURE;
        }

        $error = $this->validateTarget($payment);

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $supersedingPayment = $this->resolveSupersedingPayment($payment);

        if ($this->validationError !== null) {
            $this->error($this->validationError);

            return self::FAILURE;
        }

        if ($supersedingPayment instanceof Payment && $supersedingPayment->id === $payment->id) {
            $this->error('The superseding payment cannot be the same payment.');

            return self::FAILURE;
        }

        $latestActivePayment = $this->latestActiveLookingPlanSubscription($payment->customer_id);

        if (! (bool) $this->option('force-current')
            && $latestActivePayment instanceof Payment
            && $latestActivePayment->id === $payment->id) {
            $this->error('Refusing to cancel the latest active-looking FIB plan subscription without --force-current.');

            return self::FAILURE;
        }

        // Paid recurring access uses the same durable intent as customer cancellation.
        // The separate legacy unfulfilled-checkout path below does not own paid access.
        if ($execute && $payment->isFulfilled()) {
            DB::transaction(function () use ($payment) {
                \App\Models\Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $latest = $this->latestActiveLookingPlanSubscription($locked->customer_id);
                if ($this->validateTarget($locked) !== null || (! $this->option('force-current') && $latest?->id === $locked->id)) {
                    throw new \RuntimeException('Cancellation target changed; review the operation again.');
                }
                app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->request($locked, 'admin_cancel',
                    ['type' => 'operator', 'reason' => (string) $this->option('reason')]);
            });
            $context = data_get($payment->fresh()->meta, 'provider_cancellation', []);
            $this->info('Cancellation intent recorded; provider state: '.($context['state'] ?? 'pending').'. Paid access is retained.');

            return self::SUCCESS;
        }

        $providerStatus = null;
        $providerActiveUntil = null;
        $providerLastPaymentAt = null;
        $providerCheckError = null;

        try {
            $status = $this->subscriptions->getStatus($payment);
            $providerStatus = $this->subscriptions->normalizeProviderStatus($status->status);
            $providerActiveUntil = $status->activeUntil;
            $providerLastPaymentAt = $status->lastPaymentAt;
        } catch (\Throwable $exception) {
            $providerCheckError = $exception->getMessage();
        }

        $this->renderPreview($payment, $supersedingPayment, $dryRun, $providerStatus, $providerCheckError);

        if ($dryRun) {
            $this->comment('Dry-run only. No provider cancellation was executed.');

            return self::SUCCESS;
        }

        $result = $this->cancellation->cancel($payment);

        if (($result['result'] ?? null) === 'provider_error') {
            $this->recordCancelFailure($payment, $supersedingPayment, $result);

            $this->error('Provider cancellation failed. Local row was left unchanged.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($payment, $supersedingPayment, $result, $providerStatus, $providerActiveUntil, $providerLastPaymentAt) {
            \App\Models\Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $safeProviderStatus = $result['provider_status'] ?? $providerStatus ?? $locked->provider_subscription_status;
            $lastPaymentAt = $result['last_payment_at'] ?? $providerLastPaymentAt;
            $paidThrough = $lastPaymentAt ? ($result['active_until'] ?? $providerActiveUntil) : null;
            if (! $lastPaymentAt || ($locked->last_payment_at && $lastPaymentAt->lt($locked->last_payment_at))) {
                $lastPaymentAt = $locked->last_payment_at;
            }
            if (! $paidThrough || ($locked->active_until && $paidThrough->lt($locked->active_until))) {
                $paidThrough = $locked->active_until;
            }
            $paidHistoryShouldRemain = $locked->isApplied() || $locked->paid_at !== null || $locked->status === PaymentStatus::PAID;
            $meta = array_merge((array) ($locked->meta ?? []), [
                'superseded_by_payment_id' => $supersedingPayment?->id,
                'superseded_by_fib_subscription_id' => $supersedingPayment?->fib_subscription_id,
                'superseded_at' => now()->toIso8601String(),
                'cancel_reason' => (string) $this->option('reason'),
                'operator_cancel' => [
                    'executed_at' => now()->toIso8601String(),
                    'result' => $result['result'] ?? null,
                    'provider_status' => $safeProviderStatus,
                ],
            ]);

            $locked->forceFill([
                'status' => $paidHistoryShouldRemain ? $locked->status : PaymentStatus::CANCELED,
                'internal_status' => $paidHistoryShouldRemain ? $locked->internal_status : PaymentInternalStatus::CANCELED,
                'provider_status' => $safeProviderStatus,
                'provider_subscription_status' => $safeProviderStatus,
                'active_until' => $paidThrough,
                'last_payment_at' => $lastPaymentAt,
                'canceled_at' => $locked->canceled_at ?? now(),
                'cancel_response' => array_filter([
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'accepted' => true,
                    'accepted_at' => now()->toIso8601String(),
                    'result' => $result['result'] ?? 'cancel_requested',
                    'source' => 'operator_subscription_cancel',
                    'provider_status' => $safeProviderStatus,
                    'trace_id' => $result['trace_id'] ?? null,
                    'error_codes' => $result['error_codes'] ?? [],
                ], static fn (mixed $value): bool => $value !== null),
                'meta' => $meta,
            ])->save();

            $serviceSubscription = CustomerServiceSubscription::query()
                ->lockForUpdate()
                ->where('payment_id', $locked->id)
                ->latest('id')
                ->first();

            if ($serviceSubscription instanceof CustomerServiceSubscription) {
                $subscriptionMeta = array_merge((array) ($serviceSubscription->meta ?? []), [
                    'superseded_by_payment_id' => $supersedingPayment?->id,
                    'superseded_by_fib_subscription_id' => $supersedingPayment?->fib_subscription_id,
                    'superseded_at' => now()->toIso8601String(),
                    'cancel_reason' => (string) $this->option('reason'),
                ]);

                $isCurrent = $paidHistoryShouldRemain && app(\App\Services\Billing\SubscriptionCyclePolicy::class)->isCurrent($serviceSubscription);
                if ($isCurrent) {
                    unset($subscriptionMeta['superseded_at'], $subscriptionMeta['superseded_by_payment_id'], $subscriptionMeta['superseded_by_fib_subscription_id']);
                    $subscriptionMeta['cancel_source'] = 'operator';
                    $subscriptionMeta['cancel_requested_at'] = now()->toIso8601String();
                    $paymentMeta = (array) $locked->meta;
                    unset($paymentMeta['superseded_at'], $paymentMeta['superseded_by_payment_id'], $paymentMeta['superseded_by_fib_subscription_id']);
                    $locked->update(['meta' => $paymentMeta]);
                }
                $serviceSubscription->forceFill([
                    'status' => $isCurrent ? $serviceSubscription->status : 'ended',
                    'auto_renew' => false,
                    'ends_at' => $isCurrent
                        ? app(\App\Services\Billing\SubscriptionCyclePolicy::class)->boundary($serviceSubscription)
                        : ($serviceSubscription->ends_at ?? now()),
                    'canceled_at' => $serviceSubscription->canceled_at ?? now(),
                    'meta' => $subscriptionMeta,
                ])->save();
                if ($isCurrent) {
                    app(\App\Services\Billing\ExpireSubscription::class)->handle($serviceSubscription);
                }
            }

            $this->events->record($locked, [
                'event_type' => 'operator_subscription_canceled',
                'source' => 'payments:fib:cancel-subscription',
                'event_key' => sprintf(
                    'operator-subscription-cancel:%d:%s:%s',
                    (int) $locked->id,
                    (string) ($result['result'] ?? 'cancel_requested'),
                    sha1((string) ($supersedingPayment?->id ?? 'none'))
                ),
                'before_status' => $payment->status?->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'superseded_by_payment_id' => $supersedingPayment?->id,
                    'superseded_by_fib_subscription_id' => $supersedingPayment?->fib_subscription_id,
                    'cancel_reason' => (string) $this->option('reason'),
                    'provider_result' => $result['result'] ?? null,
                    'provider_status' => $safeProviderStatus,
                ],
            ]);
        }, 3);

        $this->info('Provider cancellation completed; local cancellation or supersession was recorded.');

        return self::SUCCESS;
    }

    protected function validateTarget(Payment $payment): ?string
    {
        $expectedCustomerId = (int) $this->option('customer');

        if ($expectedCustomerId > 0 && (int) $payment->customer_id !== $expectedCustomerId) {
            return 'The payment does not belong to the expected customer.';
        }

        if ($payment->provider !== PaymentProvider::FIB) {
            return 'This command only supports FIB payments.';
        }

        if ($payment->purchase_type !== PurchaseType::PLAN_SUBSCRIPTION) {
            return 'This command only supports plan subscription payments.';
        }

        if (($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT) !== PaymentProviderObjectType::SUBSCRIPTION) {
            return 'The target payment is not a FIB subscription object.';
        }

        if ($payment->resolvedPaymentMode(PaymentMode::ONE_TIME) !== PaymentMode::RECURRING) {
            return 'The target payment is not a recurring subscription checkout.';
        }

        if (trim((string) $payment->fib_subscription_id) === '') {
            return 'The target payment does not have a fib_subscription_id.';
        }

        return null;
    }

    protected function resolveSupersedingPayment(Payment $payment): ?Payment
    {
        $this->validationError = null;
        $supersedingId = (int) $this->option('superseded-by');

        if ($supersedingId <= 0) {
            return null;
        }

        $supersedingPayment = Payment::query()
            ->whereKey($supersedingId)
            ->where('customer_id', $payment->customer_id)
            ->first();

        if (! $supersedingPayment instanceof Payment) {
            $this->validationError = 'The superseding payment was not found for this customer.';

            return null;
        }

        return $supersedingPayment;
    }

    protected function latestActiveLookingPlanSubscription(int $customerId): ?Payment
    {
        return Payment::query()
            ->where('customer_id', $customerId)
            ->where('provider', PaymentProvider::FIB)
            ->where('purchase_type', PurchaseType::PLAN_SUBSCRIPTION)
            ->where('payment_mode', PaymentMode::RECURRING)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->whereNotNull('fib_subscription_id')
            ->where(function ($query) {
                $query
                    ->whereIn('provider_subscription_status', ['ACTIVE', 'SUBSCRIBED', 'PAID'])
                    ->orWhereIn('status', [
                        PaymentStatus::PENDING->value,
                        PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
                        PaymentStatus::PAID->value,
                    ]);
            })
            ->orderByDesc('id')
            ->first();
    }

    protected function renderPreview(
        Payment $payment,
        ?Payment $supersedingPayment,
        bool $dryRun,
        ?string $providerStatus,
        ?string $providerCheckError,
    ): void {
        $this->info($dryRun ? 'DRY RUN: FIB subscription cancel preview' : 'EXECUTE: FIB subscription cancel');
        $this->table(['Key', 'Value'], [
            ['payment_id', (string) $payment->id],
            ['customer_id', (string) $payment->customer_id],
            ['plan_id', (string) $payment->purchasable_id],
            ['local_status', (string) ($payment->status?->value ?? '')],
            ['internal_status', (string) ($payment->internal_status?->value ?? '')],
            ['fib_subscription_id', (string) $payment->fib_subscription_id],
            ['provider_status_local', (string) ($payment->provider_subscription_status ?? '')],
            ['provider_status_live', $providerStatus ?? 'n/a'],
            ['provider_check_error', $providerCheckError ?? 'n/a'],
            ['superseded_by_payment_id', (string) ($supersedingPayment?->id ?? '')],
            ['superseded_by_fib_subscription_id', (string) ($supersedingPayment?->fib_subscription_id ?? '')],
            ['reason', (string) $this->option('reason')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function recordCancelFailure(Payment $payment, ?Payment $supersedingPayment, array $result): void
    {
        $this->events->record($payment, [
            'event_type' => 'operator_subscription_cancel_failed',
            'source' => 'payments:fib:cancel-subscription',
            'event_key' => sprintf(
                'operator-subscription-cancel-failed:%d:%s',
                (int) $payment->id,
                sha1(json_encode($result))
            ),
            'before_status' => $payment->status?->value,
            'after_status' => $payment->status?->value,
            'meta' => [
                'superseded_by_payment_id' => $supersedingPayment?->id,
                'cancel_reason' => (string) $this->option('reason'),
                'provider_status' => $result['provider_status'] ?? null,
                'trace_id' => $result['trace_id'] ?? null,
                'error_codes' => $result['error_codes'] ?? [],
            ],
        ]);

        $this->telegram->send(
            __('FIB operator subscription cancellation failed'),
            [
                'Customer ID' => $payment->customer_id,
                'Payment ID' => $payment->id,
                'Provider ref' => $payment->providerReference(),
                'Superseded by payment' => $supersedingPayment?->id,
                'Reason' => (string) $this->option('reason'),
                'Provider status' => $result['provider_status'] ?? null,
                'Trace ID' => $result['trace_id'] ?? null,
                'Error codes' => implode(', ', $result['error_codes'] ?? []),
            ],
            'FIB operator subscription cancel'
        );
    }
}

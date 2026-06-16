<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReconcileFibSubscription extends Command
{
    protected ?string $validationError = null;

    protected $signature = 'payments:fib:reconcile-subscription
        {payment : Local payment id to reconcile}
        {--customer= : Expected customer id}
        {--manual-correction-already-applied : Require an already-corrected local Premium state and skip any credit refill}
        {--keep-provider-active : Confirm that the target provider subscription must remain active}
        {--cancel-superseded= : Optionally cancel a different superseded local payment id after reconciliation}
        {--paid-at= : Optional manual paid_at override when provider lastPaymentAt is missing}
        {--dry-run : Preview only (default unless --execute is provided)}
        {--execute : Perform the local reconciliation and any requested superseded cancellation}';

    protected $description = 'Safely reconcile a provider-paid FIB recurring service-plan subscription into local state. Dry-run by default.';

    public function __construct(
        protected FibSubscriptionService $subscriptions,
        protected FibSubscriptionMapper $mapper,
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

        $customer = $payment->customer;

        if (! $customer instanceof Customer) {
            $this->error('The payment customer could not be loaded.');

            return self::FAILURE;
        }

        $plan = $payment->purchasable;

        if (! $plan instanceof ServicePlan) {
            $this->error('The plan attached to this payment could not be loaded.');

            return self::FAILURE;
        }

        $status = null;

        try {
            $status = $this->subscriptions->getStatus($payment);
        } catch (\Throwable $exception) {
            $this->error('Provider status lookup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $providerStatus = $this->subscriptions->normalizeProviderStatus($status->status);
        $manualPaidAt = $this->manualPaidAtOverride();

        if ($this->validationError !== null) {
            $this->error($this->validationError);

            return self::FAILURE;
        }

        $providerPaymentStatus = $this->mapper->explicitPaidStatusFromPayloads(
            $status,
            is_array($payment->callback_payload) ? $payment->callback_payload : null
        );
        $hasPaidEvidence = $this->mapper->hasConfirmedPaymentEvidence(
            $status,
            is_array($payment->callback_payload) ? $payment->callback_payload : null
        );

        if (! in_array($providerStatus, ['ACTIVE', 'SUBSCRIBED'], true) || ! $hasPaidEvidence) {
            $this->error('The provider subscription is not in a safe ACTIVE/PAID state for local reconciliation.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('keep-provider-active')) {
            $this->error('Use --keep-provider-active to confirm that the target FIB subscription must remain active.');

            return self::FAILURE;
        }

        $activeSubscription = CustomerServiceSubscription::query()
            ->with('servicePlan')
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        if ((bool) $this->option('manual-correction-already-applied')) {
            $safetyError = $this->validateManualCorrectionState($customer, $plan, $activeSubscription);

            if ($safetyError !== null) {
                $this->error($safetyError);

                return self::FAILURE;
            }
        } else {
            $this->error('This command currently requires --manual-correction-already-applied for safe no-refill reconciliation.');

            return self::FAILURE;
        }

        $supersededPayment = $this->resolveSupersededPayment($payment);

        if ($this->validationError !== null) {
            $this->error($this->validationError);

            return self::FAILURE;
        }

        $this->renderPreview($payment, $plan, $activeSubscription, $status->lastPaymentAt?->toIso8601String(), $providerStatus, $providerPaymentStatus, $supersededPayment, $dryRun);

        if ($dryRun) {
            $this->comment('Dry-run only. No local reconciliation or provider cancellation was executed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($payment, $customer, $activeSubscription, $status, $providerStatus, $providerPaymentStatus, $supersededPayment, $manualPaidAt) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            /** @var CustomerServiceSubscription $lockedSubscription */
            $lockedSubscription = CustomerServiceSubscription::query()
                ->lockForUpdate()
                ->findOrFail($activeSubscription->id);

            $paidAt = $lockedPayment->paid_at
                ?? $status->lastPaymentAt
                ?? $manualPaidAt
                ?? now();

            $providerCycleKey = trim((string) ($status->lastPaymentAt?->copy()->utc()->format('Y-m-d\TH:i:s\Z') ?? ''));
            $providerCycleKey = $providerCycleKey !== ''
                ? 'fib:'.trim((string) $lockedPayment->fib_subscription_id).':'.$providerCycleKey
                : ($lockedPayment->providerRecurringCycleKey() ?? null);

            $lockedPayment->forceFill([
                'status' => PaymentStatus::PAID,
                'internal_status' => PaymentInternalStatus::APPLIED,
                'provider_status' => $providerStatus,
                'provider_subscription_status' => $providerStatus,
                'provider_payment_status' => $providerPaymentStatus ?? $lockedPayment->provider_payment_status ?? PaymentStatus::PAID->value,
                'paid_at' => $paidAt,
                'fulfilled_at' => $lockedPayment->fulfilled_at ?? now(),
                'review_required_at' => null,
                'mismatch_reason' => null,
                'active_until' => $status->activeUntil ?? $lockedPayment->active_until,
                'last_payment_at' => $status->lastPaymentAt ?? $lockedPayment->last_payment_at,
                'status_response' => $status->raw,
                'last_status_checked_at' => now(),
                'meta' => array_merge((array) ($lockedPayment->meta ?? []), [
                    'manual_reconciliation' => true,
                    'manual_correction_already_applied' => true,
                    'no_credit_refill' => true,
                    'reconciled_at' => now()->toIso8601String(),
                    'old_superseded_payment_id' => $supersededPayment?->id,
                    'provider_cycle_key' => $providerCycleKey,
                    'provider_last_payment_at' => $status->lastPaymentAt?->toIso8601String(),
                ]),
            ])->save();

            $subscriptionMeta = array_merge((array) ($lockedSubscription->meta ?? []), [
                'manual_reconciliation' => true,
                'manual_correction_already_applied' => true,
                'reconciled_at' => now()->toIso8601String(),
                'linked_payment_id' => $lockedPayment->id,
                'provider_active_until' => $status->activeUntil?->toIso8601String(),
                'period_ends_at' => $status->activeUntil?->toIso8601String(),
                'provider_last_payment_at' => $status->lastPaymentAt?->toIso8601String(),
                'provider_cycle_key' => $providerCycleKey,
                'old_superseded_payment_id' => $supersededPayment?->id,
            ]);

            $lockedSubscription->forceFill([
                'payment_id' => $lockedPayment->id,
                'source' => PaymentProvider::FIB->value,
                'provider_ref' => $lockedPayment->providerReference(),
                'auto_renew' => true,
                'renewal_strategy' => PaymentRecurringStrategy::PROVIDER_SCHEDULE->value,
                'cycle_started_on' => ($status->lastPaymentAt ?? $lockedSubscription->cycle_started_on)?->toDateString(),
                'cycle_ends_on' => ($status->activeUntil ?? $lockedSubscription->cycle_ends_on)?->toDateString(),
                'next_renewal_on' => ($status->activeUntil ?? $lockedSubscription->next_renewal_on)?->toDateString(),
                'meta' => $subscriptionMeta,
            ])->save();

            $customer->syncResolvedServicePlan($lockedSubscription);

            $this->events->record($lockedPayment, [
                'event_type' => 'operator_subscription_reconciled',
                'source' => 'payments:fib:reconcile-subscription',
                'event_key' => sprintf(
                    'operator-subscription-reconcile:%d:%s',
                    (int) $lockedPayment->id,
                    sha1((string) $lockedSubscription->id)
                ),
                'before_status' => $payment->status?->value,
                'after_status' => $lockedPayment->status->value,
                'meta' => [
                    'manual_correction_already_applied' => true,
                    'no_credit_refill' => true,
                    'linked_subscription_id' => $lockedSubscription->id,
                    'old_superseded_payment_id' => $supersededPayment?->id,
                    'provider_status' => $providerStatus,
                    'provider_payment_status' => $providerPaymentStatus,
                ],
            ]);
        }, 3);

        if ($supersededPayment instanceof Payment) {
            $cancelExitCode = $this->cancelSupersededPayment($payment, $supersededPayment);

            if ($cancelExitCode !== self::SUCCESS) {
                return $cancelExitCode;
            }
        }

        $this->telegram->send(
            __('FIB Premium subscription reconciled manually'),
            [
                'Customer ID' => $customer->id,
                'Payment ID' => $payment->id,
                'Plan' => $plan->name,
                'Provider ref' => $payment->providerReference(),
                'No credit refill' => 'yes',
                'Old superseded payment' => $supersededPayment?->id,
            ],
            'FIB operator subscription reconcile'
        );

        $this->info('Local payment and active Premium subscription were reconciled without a duplicate credit refill.');

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

    protected function validateManualCorrectionState(
        Customer $customer,
        ServicePlan $plan,
        ?CustomerServiceSubscription $activeSubscription,
    ): ?string {
        if (! $activeSubscription instanceof CustomerServiceSubscription) {
            return 'No active local service subscription exists. Refusing a no-refill reconciliation.';
        }

        if ((int) $activeSubscription->service_plan_id !== (int) $plan->id) {
            return 'The active local service subscription does not match the target payment plan.';
        }

        if ((int) ($customer->currentServicePlanId() ?? 0) !== (int) $plan->id) {
            return 'The customer current service plan does not match the target payment plan.';
        }

        $appWallet = $customer->wallet()->first();
        $apiWallet = $customer->apiWallet()->first();

        if ((int) ($appWallet?->subscription_balance_credits ?? 0) < $plan->appMonthlyCredits()) {
            return 'The app wallet subscription balance is lower than the plan allowance. Refusing a no-refill reconciliation.';
        }

        if ((int) ($apiWallet?->subscription_balance_credits ?? 0) < $plan->apiMonthlyCredits()) {
            return 'The API wallet subscription balance is lower than the plan allowance. Refusing a no-refill reconciliation.';
        }

        return null;
    }

    protected function manualPaidAtOverride(): ?Carbon
    {
        $this->validationError = null;
        $value = trim((string) $this->option('paid-at'));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            $this->validationError = 'The provided --paid-at value could not be parsed safely.';

            return null;
        }
    }

    protected function resolveSupersededPayment(Payment $payment): ?Payment
    {
        $this->validationError = null;
        $supersededId = (int) $this->option('cancel-superseded');

        if ($supersededId <= 0) {
            return null;
        }

        $superseded = Payment::query()
            ->whereKey($supersededId)
            ->where('customer_id', $payment->customer_id)
            ->first();

        if (! $superseded instanceof Payment) {
            $this->validationError = 'The superseded payment id was not found for this customer.';

            return null;
        }

        return $superseded;
    }

    protected function renderPreview(
        Payment $payment,
        ServicePlan $plan,
        ?CustomerServiceSubscription $activeSubscription,
        ?string $providerLastPaymentAt,
        ?string $providerStatus,
        ?string $providerPaymentStatus,
        ?Payment $supersededPayment,
        bool $dryRun,
    ): void {
        $this->info($dryRun ? 'DRY RUN: FIB subscription reconcile preview' : 'EXECUTE: FIB subscription reconcile');
        $this->table(['Key', 'Value'], [
            ['payment_id', (string) $payment->id],
            ['customer_id', (string) $payment->customer_id],
            ['plan_id', (string) $plan->id],
            ['plan_code', (string) $plan->code],
            ['fib_subscription_id', (string) $payment->fib_subscription_id],
            ['provider_status', $providerStatus ?? 'n/a'],
            ['provider_payment_status', $providerPaymentStatus ?? 'n/a'],
            ['provider_last_payment_at', $providerLastPaymentAt ?? 'n/a'],
            ['manual_correction_already_applied', (bool) $this->option('manual-correction-already-applied') ? 'yes' : 'no'],
            ['keep_provider_active', (bool) $this->option('keep-provider-active') ? 'yes' : 'no'],
            ['active_subscription_id', (string) ($activeSubscription?->id ?? '')],
            ['active_subscription_plan_id', (string) ($activeSubscription?->service_plan_id ?? '')],
            ['cancel_superseded_payment_id', (string) ($supersededPayment?->id ?? '')],
        ]);
    }

    protected function cancelSupersededPayment(Payment $reconciledPayment, Payment $supersededPayment): int
    {
        $result = $this->cancellation->cancel($supersededPayment);

        if (($result['result'] ?? null) === 'provider_error') {
            $this->events->record($supersededPayment, [
                'event_type' => 'operator_subscription_cancel_failed',
                'source' => 'payments:fib:reconcile-subscription',
                'event_key' => sprintf(
                    'operator-subscription-cancel-failed:%d:%d',
                    (int) $supersededPayment->id,
                    (int) $reconciledPayment->id
                ),
                'before_status' => $supersededPayment->status?->value,
                'after_status' => $supersededPayment->status?->value,
                'meta' => [
                    'superseded_by_payment_id' => $reconciledPayment->id,
                    'provider_status' => $result['provider_status'] ?? null,
                    'trace_id' => $result['trace_id'] ?? null,
                    'error_codes' => $result['error_codes'] ?? [],
                ],
            ]);

            $this->telegram->send(
                __('FIB operator superseded cancellation failed during reconciliation'),
                [
                    'Customer ID' => $supersededPayment->customer_id,
                    'Old payment ID' => $supersededPayment->id,
                    'New payment ID' => $reconciledPayment->id,
                    'Old provider ref' => $supersededPayment->providerReference(),
                    'New provider ref' => $reconciledPayment->providerReference(),
                    'Trace ID' => $result['trace_id'] ?? null,
                    'Error codes' => implode(', ', $result['error_codes'] ?? []),
                ],
                'FIB operator reconcile cancel'
            );

            $this->error('The Premium payment was reconciled locally, but the requested superseded cancellation failed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($supersededPayment, $reconciledPayment, $result) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($supersededPayment->id);
            $meta = array_merge((array) ($locked->meta ?? []), [
                'superseded_by_payment_id' => $reconciledPayment->id,
                'superseded_by_fib_subscription_id' => $reconciledPayment->fib_subscription_id,
                'superseded_at' => now()->toIso8601String(),
                'cancel_reason' => 'manual_reconcile_superseded',
            ]);

            $locked->forceFill([
                'status' => $locked->isApplied() || $locked->paid_at !== null ? $locked->status : PaymentStatus::CANCELED,
                'internal_status' => $locked->isApplied() || $locked->paid_at !== null ? $locked->internal_status : PaymentInternalStatus::CANCELED,
                'provider_status' => $result['provider_status'] ?? $locked->provider_subscription_status,
                'provider_subscription_status' => $result['provider_status'] ?? $locked->provider_subscription_status,
                'active_until' => $result['active_until'] ?? $locked->active_until,
                'last_payment_at' => $result['last_payment_at'] ?? $locked->last_payment_at,
                'canceled_at' => $locked->canceled_at ?? now(),
                'cancel_response' => array_filter([
                    'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                    'accepted' => true,
                    'accepted_at' => now()->toIso8601String(),
                    'result' => $result['result'] ?? 'cancel_requested',
                    'source' => 'payments:fib:reconcile-subscription',
                    'provider_status' => $result['provider_status'] ?? null,
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
                $serviceSubscription->forceFill([
                    'status' => 'ended',
                    'auto_renew' => false,
                    'ends_at' => $serviceSubscription->ends_at ?? now(),
                    'canceled_at' => $serviceSubscription->canceled_at ?? now(),
                    'meta' => array_merge((array) ($serviceSubscription->meta ?? []), [
                        'superseded_by_payment_id' => $reconciledPayment->id,
                        'superseded_by_fib_subscription_id' => $reconciledPayment->fib_subscription_id,
                        'superseded_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }

            $this->events->record($locked, [
                'event_type' => 'operator_subscription_canceled',
                'source' => 'payments:fib:reconcile-subscription',
                'event_key' => sprintf(
                    'operator-subscription-cancel:%d:%d',
                    (int) $locked->id,
                    (int) $reconciledPayment->id
                ),
                'before_status' => $supersededPayment->status?->value,
                'after_status' => $locked->status->value,
                'meta' => [
                    'superseded_by_payment_id' => $reconciledPayment->id,
                    'superseded_by_fib_subscription_id' => $reconciledPayment->fib_subscription_id,
                    'provider_result' => $result['result'] ?? null,
                ],
            ]);
        }, 3);

        return self::SUCCESS;
    }
}

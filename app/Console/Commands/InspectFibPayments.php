<?php

namespace App\Console\Commands;

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibConfiguration;
use App\Domain\Payments\Fib\FibFailureInterpreter;
use App\Domain\Payments\Fib\FibMapper;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibStatusReasonParser;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Payments\PaymentApplicationService;
use App\Services\Payments\PaymentSyncFailureService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InspectFibPayments extends Command
{
    protected $signature = 'payments:inspect-fib
        {--customer= : Customer id to inspect}
        {--from= : Start date (YYYY-MM-DD) for local created_at filtering}
        {--to= : End date (YYYY-MM-DD) for local created_at filtering}
        {--fib-payment-id= : Inspect a specific FIB payment id}
        {--fib-subscription-id= : Inspect a specific FIB subscription id}
        {--provider-reference= : Inspect a specific FIB payment or subscription id}
        {--local-only : Only inspect local payment rows/events and never call the live FIB API}
        {--create-missing-review : Create a review-only local row if the provider reference exists but no local payment row is linked}
        {--json : Emit JSON output}';

    protected $description = 'Inspect local FIB payment state and safely recover missing review-only rows without applying any subscription.';

    public function __construct(
        protected SyncFibCheckoutStatus $sync,
        protected FibOneTimePaymentService $payments,
        protected FibSubscriptionService $subscriptions,
        protected FibMapper $paymentMapper,
        protected FibSubscriptionMapper $subscriptionMapper,
        protected FibStatusReasonParser $reasonParser,
        protected PaymentEventRecorder $events,
        protected PaymentApplicationService $application,
        protected PaymentSyncFailureService $failures,
        protected BillingCurrencyService $billing,
        protected FibConfiguration $fibConfig,
        protected FibFailureInterpreter $failureInterpreter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $customerId = $this->validatedCustomerId();

        if ($customerId === false) {
            return self::FAILURE;
        }

        $customer = $customerId > 0 ? Customer::query()->find($customerId) : null;

        if ($customerId > 0 && ! $customer instanceof Customer) {
            $this->error("Customer [{$customerId}] was not found.");

            return self::FAILURE;
        }

        [$from, $to] = $this->normalizedDateRange();

        if ($from === false || $to === false) {
            return self::FAILURE;
        }

        $fibPaymentId = $this->normalizedStringOption('fib-payment-id');
        $fibSubscriptionId = $this->normalizedStringOption('fib-subscription-id');
        $providerReference = $this->normalizedStringOption('provider-reference');
        $localOnly = (bool) $this->option('local-only');
        $createMissingReview = (bool) $this->option('create-missing-review');

        $selectorCount = collect([$fibPaymentId, $fibSubscriptionId, $providerReference])
            ->filter(static fn (?string $value): bool => $value !== null)
            ->count();

        if ($selectorCount > 1) {
            $this->error('Choose only one provider selector: --fib-payment-id, --fib-subscription-id, or --provider-reference.');

            return self::FAILURE;
        }

        if ($customerId <= 0 && $selectorCount === 0) {
            $this->error('Provide either --customer=<id> for local inspection or a provider selector for targeted lookup.');

            return self::FAILURE;
        }

        if ($createMissingReview && ! $customer instanceof Customer && $selectorCount > 0) {
            $this->error('The --create-missing-review flag requires --customer=<id> so the recovery row can be assigned safely.');

            return self::FAILURE;
        }

        $report = $selectorCount === 0
            ? $this->inspectLocalScope($customer, $from, $to, $createMissingReview)
            : $this->inspectProviderReference(
                $customer,
                $from,
                $to,
                $fibPaymentId,
                $fibSubscriptionId,
                $providerReference,
                $createMissingReview,
                $localOnly,
            );

        $report = $this->finalizeReport($report, $fibPaymentId, $fibSubscriptionId, $providerReference, $localOnly);

        return $this->renderReport($report);
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspectLocalScope(
        ?Customer $customer,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        bool $createMissingReview,
    ): array {
        $payments = $this->localPaymentsQuery($customer?->id, $from, $to)
            ->orderBy('created_at')
            ->get();
        $events = $this->relatedEventsForPayments($payments);
        $warnings = [];
        $suspicious = $this->collectSuspiciousConditions($payments, $events);

        if ($payments->isEmpty()) {
            $warnings[] = 'No local FIB rows found for the requested scope.';
            $warnings[] = 'FIB provider discovery by customer/date is not supported by the current API/docs.';
            $warnings[] = 'Please copy the FIB payment/subscription ID from FIB Business and rerun with --provider-reference=<id>.';
        }

        if ($createMissingReview) {
            $warnings[] = 'The --create-missing-review flag requires a FIB provider reference; no recovery row was created.';
        }

        return [
            'mode' => 'local_scope',
            'filters' => $this->filterSummary($customer, $from, $to),
            'warnings' => array_values(array_unique($warnings)),
            'local_rows' => $this->paymentSummaries($payments, $events),
            'payment_events' => $events->map(fn (PaymentEvent $event): array => $this->eventSummary($event))->values()->all(),
            'suspicious_conditions' => array_values(array_unique($suspicious)),
            'provider_lookup' => null,
            'possible_local_candidates' => [],
            'recovery_action' => null,
            'exit_code' => self::SUCCESS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspectProviderReference(
        ?Customer $customer,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string $fibPaymentId,
        ?string $fibSubscriptionId,
        ?string $providerReference,
        bool $createMissingReview,
        bool $localOnly,
    ): array {
        $localPayment = $this->findLocalPaymentByReference($fibPaymentId, $fibSubscriptionId, $providerReference);
        $warnings = [];
        $recoveryAction = null;
        $requestedReference = $fibPaymentId ?? $fibSubscriptionId ?? $providerReference;

        if ($localPayment instanceof Payment) {
            if ($customer instanceof Customer && (int) $localPayment->customer_id !== (int) $customer->id) {
                $warnings[] = sprintf(
                    'Matched provider reference belongs to local customer_id=%d, not the requested customer_id=%d.',
                    (int) $localPayment->customer_id,
                    (int) $customer->id,
                );
            }

            if ($localOnly) {
                $events = $this->relatedEventsForPayments(collect([$localPayment]));
                $localRows = $this->paymentSummaries(collect([$localPayment]), $events);

                return [
                    'mode' => 'provider_reference_existing_row_local_only',
                    'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                    'warnings' => array_values(array_unique($warnings)),
                    'local_rows' => $localRows,
                    'payment_events' => $events->map(fn (PaymentEvent $event): array => $this->eventSummary($event))->values()->all(),
                    'suspicious_conditions' => array_values(array_unique($this->collectSuspiciousConditions(collect([$localPayment]), $events))),
                    'provider_lookup' => array_merge(
                        $this->lookupSummaryFromPayment($localPayment, false, false, 'Local-only mode skipped the live FIB provider lookup and left the stored provider status unchanged.'),
                        ['local_only' => true]
                    ),
                    'possible_local_candidates' => [],
                    'recovery_action' => [
                        'status' => 'matched_existing_row_local_only',
                        'message' => sprintf('Matched local payment row #%d without calling the provider API.', (int) $localPayment->id),
                    ],
                    'exit_code' => self::SUCCESS,
                ];
            }

            try {
                $localPayment = $this->refreshExistingPaymentForInspection($localPayment);
                $events = $this->relatedEventsForPayments(collect([$localPayment]));
                $localRows = $this->paymentSummaries(collect([$localPayment]), $events);

                return [
                    'mode' => 'provider_reference_existing_row',
                    'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                    'warnings' => array_values(array_unique($warnings)),
                    'local_rows' => $localRows,
                    'payment_events' => $events->map(fn (PaymentEvent $event): array => $this->eventSummary($event))->values()->all(),
                    'suspicious_conditions' => array_values(array_unique($this->collectSuspiciousConditions(collect([$localPayment]), $events))),
                    'provider_lookup' => $this->lookupSummaryFromPayment($localPayment, true, true),
                    'possible_local_candidates' => [],
                    'recovery_action' => [
                        'status' => 'matched_existing_row',
                        'message' => sprintf('Matched local payment row #%d and refreshed provider status without fulfillment.', (int) $localPayment->id),
                    ],
                    'exit_code' => self::SUCCESS,
                ];
            } catch (\Throwable $exception) {
                $failure = $this->failures->capture($localPayment, $exception, 'artisan_fib_inspect');
                $localPayment = $localPayment->fresh() ?? $localPayment;
                $events = $this->relatedEventsForPayments(collect([$localPayment]));
                $localRows = $this->paymentSummaries(collect([$localPayment]), $events);
                $warnings[] = (string) ($failure['safe_message'] ?? 'FIB provider lookup failed for the matched local row.');

                return [
                    'mode' => 'provider_reference_existing_row_lookup_failed',
                    'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                    'warnings' => array_values(array_unique($warnings)),
                    'local_rows' => $localRows,
                    'payment_events' => $events->map(fn (PaymentEvent $event): array => $this->eventSummary($event))->values()->all(),
                    'suspicious_conditions' => array_values(array_unique($this->collectSuspiciousConditions(collect([$localPayment]), $events))),
                    'provider_lookup' => $this->lookupFailureSummary(
                        $failure,
                        $localPayment->provider_object_type,
                        true,
                        true,
                        false,
                    ) + [
                        'purchase_type' => $localPayment->purchase_type?->value ?? null,
                        'payment_mode' => $localPayment->payment_mode?->value ?? null,
                    ],
                    'possible_local_candidates' => [],
                    'recovery_action' => [
                        'status' => 'matched_existing_row_lookup_failed',
                        'message' => sprintf('Matched local payment row #%d, but the provider refresh failed. No fulfillment was applied.', (int) $localPayment->id),
                    ],
                    'exit_code' => self::FAILURE,
                ];
            }
        }

        if ($localOnly) {
            $warnings[] = 'No local payment row matched the requested provider reference.';

            if ($createMissingReview) {
                $warnings[] = 'Recovery row creation was skipped because local-only mode never performs provider verification.';
            }

            return [
                'mode' => 'provider_reference_local_only_missing_row',
                'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                'warnings' => array_values(array_unique($warnings)),
                'local_rows' => [],
                'payment_events' => [],
                'suspicious_conditions' => ['no local rows found'],
                'provider_lookup' => [
                    'attempted' => false,
                    'local_only' => true,
                    'provider_reference' => $requestedReference,
                    'message' => 'Local-only mode skipped the live FIB provider lookup.',
                ],
                'possible_local_candidates' => [],
                'recovery_action' => [
                    'status' => 'not_created_local_only',
                    'message' => 'No recovery row was created because local-only mode skipped provider verification.',
                ],
                'exit_code' => self::SUCCESS,
            ];
        }

        $providerLookup = $this->lookupProviderReference($fibPaymentId, $fibSubscriptionId, $providerReference);

        if (! ($providerLookup['found'] ?? false)) {
            $warnings[] = 'No local payment row matched the requested provider reference.';

            if ($providerLookup['message'] ?? null) {
                $warnings[] = (string) $providerLookup['message'];
            }

            return [
                'mode' => 'provider_reference_missing_row',
                'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                'warnings' => array_values(array_unique($warnings)),
                'local_rows' => [],
                'payment_events' => [],
                'suspicious_conditions' => [],
                'provider_lookup' => $providerLookup,
                'possible_local_candidates' => [],
                'recovery_action' => [
                    'status' => 'provider_lookup_failed',
                    'message' => 'Provider lookup failed; no local recovery row was created.',
                ],
                'exit_code' => self::FAILURE,
            ];
        }

        $possibleCandidates = $customer instanceof Customer
            ? $this->possibleLocalCandidates($customer, $from, $to, $providerLookup)
            : collect();
        $candidateSummaries = $this->paymentSummaries($possibleCandidates);
        $eventRows = $this->eventsForProviderLookup($providerLookup);

        if (! $createMissingReview) {
            $warnings[] = 'No local payment row matched the requested provider reference.';
            $warnings[] = 'Manual recovery row was not created.';
            $warnings[] = $customer instanceof Customer
                ? sprintf('Rerun with --customer=%d --create-missing-review after you verify this provider reference belongs to the customer.', (int) $customer->id)
                : 'Pass --customer=<id> together with --create-missing-review if you want to store a safe local review row.';

            if ($possibleCandidates->isNotEmpty()) {
                $warnings[] = 'Possible local rows exist, but the command did not auto-link them. Review them manually before deciding whether to create a dedicated recovery row.';
            }

            return [
                'mode' => 'provider_reference_missing_row',
                'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                'warnings' => array_values(array_unique($warnings)),
                'local_rows' => [],
                'payment_events' => $eventRows->map(fn (PaymentEvent $event): array => $this->eventSummary($event))->values()->all(),
                'suspicious_conditions' => [],
                'provider_lookup' => $providerLookup,
                'possible_local_candidates' => $candidateSummaries,
                'recovery_action' => [
                    'status' => 'not_created',
                    'message' => 'Provider status was inspected, but no review-only recovery row was created.',
                ],
                'exit_code' => self::SUCCESS,
            ];
        }

        if (! $customer instanceof Customer) {
            return [
                'mode' => 'provider_reference_missing_row',
                'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
                'warnings' => ['The --create-missing-review flag requires --customer=<id>.'],
                'local_rows' => [],
                'payment_events' => [],
                'suspicious_conditions' => [],
                'provider_lookup' => $providerLookup,
                'possible_local_candidates' => $candidateSummaries,
                'recovery_action' => [
                    'status' => 'not_created',
                    'message' => 'Recovery row was not created because no customer id was provided.',
                ],
                'exit_code' => self::FAILURE,
            ];
        }

        $payment = $this->createOrUpdateMissingReviewRow($customer, $providerLookup, $possibleCandidates);
        $events = $this->relatedEventsForPayments(collect([$payment]))->merge($eventRows)->unique('id')->values();
        $recoveryAction = [
            'status' => 'created_or_updated_review_row',
            'payment_id' => (int) $payment->id,
            'message' => sprintf('Stored local payment row #%d as review-required without applying any billing change.', (int) $payment->id),
        ];

        if ($possibleCandidates->isNotEmpty()) {
            $warnings[] = 'Possible local rows existed with missing provider linkage; the command created a separate review row instead of auto-linking them.';
        }

        return [
            'mode' => 'provider_reference_recovered',
            'filters' => $this->filterSummary($customer, $from, $to, $fibPaymentId, $fibSubscriptionId, $providerReference),
            'warnings' => array_values(array_unique($warnings)),
            'local_rows' => $this->paymentSummaries(collect([$payment]), $events),
            'payment_events' => $events->map(fn (PaymentEvent $event): array => $this->eventSummary($event))->values()->all(),
            'suspicious_conditions' => array_values(array_unique($this->collectSuspiciousConditions(collect([$payment]), $events))),
            'provider_lookup' => $providerLookup,
            'possible_local_candidates' => $candidateSummaries,
            'recovery_action' => $recoveryAction,
            'exit_code' => self::SUCCESS,
        ];
    }

    protected function validatedCustomerId(): int|false
    {
        $raw = trim((string) $this->option('customer'));

        if ($raw === '') {
            return 0;
        }

        if (! preg_match('/^\d+$/', $raw)) {
            $this->error('The --customer option must be a positive integer.');

            return false;
        }

        return max(0, (int) $raw);
    }

    protected function normalizedStringOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{0:CarbonInterface|null|false,1:CarbonInterface|null|false}
     */
    protected function normalizedDateRange(): array
    {
        $fromRaw = trim((string) $this->option('from'));
        $toRaw = trim((string) $this->option('to'));

        try {
            $from = $fromRaw !== '' ? Carbon::parse($fromRaw)->startOfDay() : null;
            $to = $toRaw !== '' ? Carbon::parse($toRaw)->endOfDay() : null;
        } catch (\Throwable) {
            $this->error('The --from/--to options must be valid dates, for example 2026-05-08.');

            return [false, false];
        }

        if ($from instanceof CarbonInterface && ! $to instanceof CarbonInterface) {
            $to = now()->endOfDay();
        }

        if (! $from instanceof CarbonInterface && $to instanceof CarbonInterface) {
            $from = $to->copy()->startOfDay();
        }

        if ($from instanceof CarbonInterface && $to instanceof CarbonInterface && $from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    protected function localPaymentsQuery(?int $customerId, ?CarbonInterface $from, ?CarbonInterface $to)
    {
        $query = Payment::query()
            ->where('provider', PaymentProvider::FIB)
            ->with('events');

        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        if ($from instanceof CarbonInterface && $to instanceof CarbonInterface) {
            $query->where(function ($builder) use ($from, $to) {
                $builder
                    ->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('paid_at', [$from, $to])
                    ->orWhereBetween('last_payment_at', [$from, $to])
                    ->orWhereBetween('last_callback_received_at', [$from, $to]);
            });
        }

        return $query;
    }

    protected function findLocalPaymentByReference(
        ?string $fibPaymentId,
        ?string $fibSubscriptionId,
        ?string $providerReference,
    ): ?Payment {
        $query = Payment::query()->where('provider', PaymentProvider::FIB);

        if ($fibPaymentId !== null) {
            return $query->where('fib_payment_id', $fibPaymentId)->first();
        }

        if ($fibSubscriptionId !== null) {
            return $query->where('fib_subscription_id', $fibSubscriptionId)->first();
        }

        if ($providerReference !== null) {
            return $query
                ->where(function ($builder) use ($providerReference) {
                    $builder
                        ->where('fib_payment_id', $providerReference)
                        ->orWhere('fib_subscription_id', $providerReference);
                })
                ->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function lookupProviderReference(
        ?string $fibPaymentId,
        ?string $fibSubscriptionId,
        ?string $providerReference,
    ): array {
        if ($fibPaymentId !== null) {
            return $this->lookupPaymentId($fibPaymentId);
        }

        if ($fibSubscriptionId !== null) {
            return $this->lookupSubscriptionId($fibSubscriptionId);
        }

        if ($providerReference === null) {
            return [
                'found' => false,
                'message' => 'No provider reference was supplied for live provider lookup.',
            ];
        }

        $paymentLookup = $this->lookupPaymentId($providerReference, false);

        if ($paymentLookup['found'] ?? false) {
            return $paymentLookup;
        }

        $subscriptionLookup = $this->lookupSubscriptionId($providerReference, false);

        if ($subscriptionLookup['found'] ?? false) {
            return $subscriptionLookup;
        }

        return $this->combineProviderLookupFailures($providerReference, $paymentLookup, $subscriptionLookup);
    }

    /**
     * @return array<string, mixed>
     */
    protected function lookupPaymentId(string $providerPaymentId, bool $includeFailureMessage = true): array
    {
        try {
            $status = $this->payments->getStatusByPaymentId($providerPaymentId);
            $mapped = $this->paymentMapper->toLocalStatus($status);

            return [
                'found' => true,
                'provider_reference' => $providerPaymentId,
                'provider_reference_type' => 'payment',
                'provider_object_type' => PaymentProviderObjectType::PAYMENT->value,
                'mapped_local_status' => $mapped->value,
                'provider_status_raw' => $status->status,
                'reason' => $this->reasonParser->reasonFromRaw($status->raw) ?: $status->decliningReason,
                'error_codes' => $this->reasonParser->errorCodesFromRaw($status->raw),
                'http_status' => 200,
                'fib_error_code' => null,
                'fib_error_title' => null,
                'fib_trace_id' => null,
                'display_error' => null,
                'safe_message' => null,
                'errors' => [],
                'guidance' => [],
                'amount_iqd' => (int) data_get($status->amount, 'amount', 0),
                'currency' => (string) data_get($status->amount, 'currency', 'IQD'),
                'valid_until' => $status->validUntil?->toIso8601String(),
                'paid_at' => $status->paidAt?->toIso8601String(),
                'declined_at' => $status->declinedAt?->toIso8601String(),
                'active_until' => null,
                'last_payment_at' => null,
                'readable_code' => null,
                'provider_links' => [],
                'interval' => null,
                'trial_period' => null,
                'title' => null,
                'description' => null,
                'raw' => $status->raw,
            ];
        } catch (\Throwable $exception) {
            return $this->providerLookupFailure(
                $providerPaymentId,
                PaymentProviderObjectType::PAYMENT,
                $exception,
                $includeFailureMessage,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function lookupSubscriptionId(string $providerSubscriptionId, bool $includeFailureMessage = true): array
    {
        try {
            $status = $this->subscriptions->getStatusBySubscriptionId($providerSubscriptionId);
            $mapped = $this->subscriptionMapper->toLocalStatus($status);

            return [
                'found' => true,
                'provider_reference' => $providerSubscriptionId,
                'provider_reference_type' => 'subscription',
                'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION->value,
                'mapped_local_status' => $mapped->value,
                'provider_status_raw' => $status->status,
                'reason' => $this->reasonParser->reasonFromRaw($status->raw),
                'error_codes' => $this->reasonParser->errorCodesFromRaw($status->raw),
                'http_status' => 200,
                'fib_error_code' => null,
                'fib_error_title' => null,
                'fib_trace_id' => null,
                'display_error' => null,
                'safe_message' => null,
                'errors' => [],
                'guidance' => [],
                'amount_iqd' => (int) data_get($status->amount, 'amount', 0),
                'currency' => (string) data_get($status->amount, 'currency', 'IQD'),
                'valid_until' => $status->validUntil?->toIso8601String(),
                'paid_at' => $status->lastPaymentAt?->toIso8601String(),
                'declined_at' => null,
                'active_until' => $status->activeUntil?->toIso8601String(),
                'last_payment_at' => $status->lastPaymentAt?->toIso8601String(),
                'readable_code' => $status->readableCode,
                'provider_links' => $status->providerLinks,
                'interval' => $status->interval,
                'trial_period' => $status->trialPeriod,
                'title' => $status->title,
                'description' => $status->description,
                'raw' => $status->raw,
            ];
        } catch (\Throwable $exception) {
            return $this->providerLookupFailure(
                $providerSubscriptionId,
                PaymentProviderObjectType::SUBSCRIPTION,
                $exception,
                $includeFailureMessage,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerLookupFailure(
        string $providerReference,
        PaymentProviderObjectType $providerObjectType,
        \Throwable $exception,
        bool $includeFailureMessage,
    ): array {
        $profileLabel = $providerObjectType->isSubscription() ? 'subscription' : 'payment';
        $details = $this->failureInterpreter->describe(
            $exception,
            $profileLabel,
            $providerReference,
            'artisan_fib_inspect',
            $providerObjectType->isSubscription() ? 'subscription_status' : 'payment_status',
            $profileLabel,
        );
        $displayError = (string) ($details['display_error'] ?? trim($exception->getMessage()));

        return [
            'found' => false,
            'provider_reference' => $providerReference,
            'provider_reference_type' => $details['provider_reference_type'] ?? $profileLabel,
            'provider_object_type' => $providerObjectType->value,
            'http_status' => $details['http_status'] ?? null,
            'fib_error_code' => $details['fib_error_code'] ?? null,
            'fib_error_title' => $details['fib_error_title'] ?? null,
            'fib_trace_id' => $details['fib_trace_id'] ?? null,
            'error_codes' => array_values(array_filter([(string) ($details['fib_error_code'] ?? '')])),
            'message' => $includeFailureMessage
                ? sprintf('FIB %s lookup failed: %s', $profileLabel, $displayError)
                : $displayError,
            'display_error' => $displayError,
            'errors' => array_values(array_filter([$displayError])),
            'guidance' => array_values(array_unique(array_filter([(string) ($details['safe_message'] ?? '')]))),
            'safe_message' => $details['safe_message'] ?? null,
            'endpoint' => $details['endpoint'] ?? null,
            'profile' => $details['profile'] ?? null,
            'transient' => (bool) ($details['transient'] ?? false),
            'permanent' => (bool) ($details['permanent'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function combineProviderLookupFailures(string $providerReference, array $paymentLookup, array $subscriptionLookup): array
    {
        $displayErrors = array_values(array_unique(array_filter([
            (string) ($paymentLookup['display_error'] ?? ''),
            (string) ($subscriptionLookup['display_error'] ?? ''),
        ])));
        $guidance = array_values(array_unique(array_filter([
            ...((array) ($paymentLookup['guidance'] ?? [])),
            ...((array) ($subscriptionLookup['guidance'] ?? [])),
        ])));
        $httpStatuses = array_values(array_unique(array_filter([
            $paymentLookup['http_status'] ?? null,
            $subscriptionLookup['http_status'] ?? null,
        ], static fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))));
        $fibErrorCodes = array_values(array_unique(array_filter([
            (string) ($paymentLookup['fib_error_code'] ?? ''),
            (string) ($subscriptionLookup['fib_error_code'] ?? ''),
        ])));
        $fibErrorTitles = array_values(array_unique(array_filter([
            (string) ($paymentLookup['fib_error_title'] ?? ''),
            (string) ($subscriptionLookup['fib_error_title'] ?? ''),
        ])));
        $traceIds = array_values(array_unique(array_filter([
            (string) ($paymentLookup['fib_trace_id'] ?? ''),
            (string) ($subscriptionLookup['fib_trace_id'] ?? ''),
        ])));

        return [
            'found' => false,
            'provider_reference' => $providerReference,
            'provider_reference_type' => 'unknown',
            'provider_object_type' => null,
            'http_status' => count($httpStatuses) === 1 ? (int) $httpStatuses[0] : null,
            'fib_error_code' => count($fibErrorCodes) === 1 ? $fibErrorCodes[0] : null,
            'fib_error_title' => count($fibErrorTitles) === 1 ? $fibErrorTitles[0] : null,
            'fib_trace_id' => count($traceIds) === 1 ? $traceIds[0] : null,
            'message' => 'Provider lookup failed for both the payment and subscription profiles. Please verify the ID from FIB Business before retrying.',
            'display_error' => implode(' | ', $displayErrors),
            'errors' => $displayErrors,
            'guidance' => $guidance,
            'attempts' => [
                'payment' => $paymentLookup,
                'subscription' => $subscriptionLookup,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lookupSummaryFromPayment(
        Payment $payment,
        bool $attempted,
        bool $statusRefreshed,
        ?string $message = null,
    ): array {
        return array_filter([
            'attempted' => $attempted,
            'matched_local_row' => true,
            'matched_local_payment_id' => (int) $payment->id,
            'found' => $attempted ? true : null,
            'provider_reference' => $payment->providerReference(),
            'provider_reference_type' => $payment->isProviderSubscriptionObject() ? 'subscription' : 'payment',
            'provider_object_type' => $payment->provider_object_type?->value ?? null,
            'purchase_type' => $payment->purchase_type?->value ?? null,
            'payment_mode' => $payment->payment_mode?->value ?? null,
            'mapped_local_status' => $payment->status?->value ?? null,
            'provider_status_raw' => $payment->providerStatusLabel(),
            'readable_code' => $payment->readable_code,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'last_payment_at' => $payment->last_payment_at?->toIso8601String(),
            'status_refreshed' => $statusRefreshed,
            'message' => $message,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function lookupFailureSummary(
        array $details,
        PaymentProviderObjectType|string|null $providerObjectType = null,
        bool $matchedLocalRow = false,
        bool $attempted = true,
        bool $statusRefreshed = false,
    ): array {
        $providerObjectValue = $providerObjectType instanceof PaymentProviderObjectType
            ? $providerObjectType->value
            : (is_string($providerObjectType) ? $providerObjectType : null);
        $displayError = trim((string) ($details['display_error'] ?? $details['message'] ?? ''));
        $safeMessage = trim((string) ($details['safe_message'] ?? ''));

        return array_filter([
            'attempted' => $attempted,
            'matched_local_row' => $matchedLocalRow,
            'status_refreshed' => $statusRefreshed,
            'found' => false,
            'provider_reference' => $details['provider_reference'] ?? null,
            'provider_reference_type' => $details['provider_reference_type'] ?? null,
            'provider_object_type' => $providerObjectValue,
            'http_status' => $details['http_status'] ?? null,
            'fib_error_code' => $details['fib_error_code'] ?? null,
            'fib_error_title' => $details['fib_error_title'] ?? null,
            'fib_trace_id' => $details['fib_trace_id'] ?? null,
            'display_error' => $displayError !== '' ? $displayError : null,
            'safe_message' => $safeMessage !== '' ? $safeMessage : null,
            'message' => $safeMessage !== '' ? $safeMessage : ($displayError !== '' ? $displayError : null),
            'errors' => $displayError !== '' ? [$displayError] : [],
            'guidance' => $safeMessage !== '' ? [$safeMessage] : [],
            'endpoint' => $details['endpoint'] ?? null,
            'profile' => $details['profile'] ?? null,
            'transient' => (bool) ($details['transient'] ?? false),
            'permanent' => (bool) ($details['permanent'] ?? false),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function finalizeReport(
        array $report,
        ?string $fibPaymentId,
        ?string $fibSubscriptionId,
        ?string $providerReference,
        bool $localOnly,
    ): array {
        $requestedReference = $fibPaymentId ?? $fibSubscriptionId ?? $providerReference;
        $warnings = collect((array) ($report['warnings'] ?? []));

        foreach ($this->reportWarnings($report, $requestedReference, $localOnly) as $warning) {
            $warnings->push($warning);
        }

        $report['warnings'] = $warnings
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning): string => trim($warning))
            ->unique()
            ->values()
            ->all();
        $report['environment'] = $this->environmentSummary();

        return $report;
    }

    /**
     * @return array<int, string>
     */
    protected function reportWarnings(array $report, ?string $requestedReference, bool $localOnly): array
    {
        $warnings = [];

        if ($localOnly) {
            $warnings[] = 'Local-only mode skipped the FIB provider API lookup and only inspected shared local DB rows/events.';
        }

        if ($requestedReference !== null && $this->looksLikeBusinessTransactionReference($requestedReference)) {
            $warnings[] = 'This reference looks like a FIB Business transaction/reference ID, not necessarily the API paymentId. Provider lookup may fail unless FIB supports transaction-reference lookup.';
        }

        if ($requestedReference !== null && $this->runningInLocalStyleEnvironment()) {
            $warnings[] = 'Local testing note: provider lookup only works if your local .env uses valid FIB sandbox credentials and the provider reference belongs to the same FIB environment. Public/production FIB lookups often require matching HTTPS environment credentials. If local lookup fails with "Jwt issuer is not configured", rerun with --local-only for DB-only inspection or test from production with the production FIB .env.';
        }

        foreach ((array) data_get($report, 'provider_lookup.guidance', []) as $guidance) {
            if (is_string($guidance) && trim($guidance) !== '') {
                $warnings[] = trim($guidance);
            }
        }

        if ($this->hasUnconfirmedProviderCheckout($report)) {
            $warnings[] = 'This appears to be an uncompleted or unconfirmed FIB checkout/subscription. Do not fulfill unless FIB Business confirms a matching paid transaction.';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @return array<string, string>
     */
    protected function environmentSummary(): array
    {
        $paymentProfile = $this->fibConfig->profile('payment');
        $subscriptionProfile = $this->fibConfig->profile('subscription');
        $callbackBaseUrl = trim((string) config('fib.callback_base_url', config('app.url', '')));

        return [
            'APP_ENV' => (string) app()->environment(),
            'RUNNING_CONTEXT' => $this->runningInLocalStyleEnvironment() ? 'local' : 'production',
            'FIB_MODE' => (string) $this->fibConfig->environment(),
            'FIB_PAYMENT_BASE_URL_HOST' => $this->hostOnly((string) $paymentProfile['base_url']),
            'FIB_PAYMENT_CLIENT_ID_CONFIGURED' => $paymentProfile['client_id'] !== '' ? 'yes' : 'no',
            'FIB_PAYMENT_CLIENT_SECRET_CONFIGURED' => $paymentProfile['client_secret'] !== '' ? 'yes' : 'no',
            'FIB_SUBSCRIPTION_BASE_URL_HOST' => $this->hostOnly((string) $subscriptionProfile['base_url']),
            'FIB_SUBSCRIPTION_CLIENT_ID_CONFIGURED' => $subscriptionProfile['client_id'] !== '' ? 'yes' : 'no',
            'FIB_SUBSCRIPTION_CLIENT_SECRET_CONFIGURED' => $subscriptionProfile['client_secret'] !== '' ? 'yes' : 'no',
            'FIB_CALLBACK_BASE_URL_HOST' => $callbackBaseUrl !== '' ? $this->hostOnly($callbackBaseUrl) : '',
            'FIB_CALLBACK_URL_CONFIGURED' => $callbackBaseUrl !== '' ? 'yes' : 'no',
            'FIB_CALLBACK_SECRET_CONFIGURED' => trim((string) config('fib.callback_secret', '')) !== '' ? 'yes' : 'no',
        ];
    }

    protected function runningInLocalStyleEnvironment(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    protected function looksLikeBusinessTransactionReference(string $reference): bool
    {
        $reference = strtoupper(trim($reference));

        return $reference !== '' && str_starts_with($reference, 'IQ');
    }

    protected function hostOnly(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && trim($host) !== '') {
            return $host;
        }

        return trim($url);
    }

    protected function stringifyReportValue(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '';
            }

            $lines = collect($value)
                ->filter(fn (mixed $item): bool => ! is_array($item) && ! is_object($item))
                ->map(fn (mixed $item): string => '- '.(string) $item)
                ->values();

            if ($lines->isNotEmpty()) {
                return $lines->implode(PHP_EOL);
            }

            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) ($value ?? '');
    }

    protected function refreshExistingPaymentForInspection(Payment $payment): Payment
    {
        $payment = $this->sync
            ->handle($payment, 'artisan_fib_inspect', null, false, false)
            ->fresh() ?? $payment->fresh() ?? $payment;

        if ($payment->status === PaymentStatus::PAID && ! $payment->isApplied()) {
            $assessment = $this->application->assess($payment);

            if (! ($assessment['can_apply'] ?? false)) {
                $this->application->markRequiresReview(
                    $payment,
                    (string) ($assessment['reason'] ?? 'Payment received but manual review is required.'),
                    (array) ($assessment['context'] ?? []),
                );

                $payment = $payment->fresh() ?? $payment;
            }
        }

        return $payment;
    }

    protected function possibleLocalCandidates(
        Customer $customer,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        array $providerLookup,
    ): Collection {
        $providerObjectType = PaymentProviderObjectType::from($providerLookup['provider_object_type']);

        $query = $this->localPaymentsQuery((int) $customer->id, $from, $to);

        if ($providerObjectType->isSubscription()) {
            $query
                ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
                ->whereNull('fib_subscription_id')
                ->whereIn('purchase_type', [
                    PurchaseType::PLAN_SUBSCRIPTION->value,
                    PurchaseType::STORAGE_SUBSCRIPTION->value,
                ]);
        } else {
            $query
                ->where('provider_object_type', PaymentProviderObjectType::PAYMENT)
                ->whereNull('fib_payment_id');
        }

        $amountIqd = (int) ($providerLookup['amount_iqd'] ?? 0);

        if ($amountIqd > 0) {
            $query->where('amount', $amountIqd);
        }

        return $query
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    protected function createOrUpdateMissingReviewRow(
        Customer $customer,
        array $providerLookup,
        Collection $possibleCandidates,
    ): Payment {
        $providerObjectType = PaymentProviderObjectType::from($providerLookup['provider_object_type']);
        $providerReference = (string) $providerLookup['provider_reference'];
        $existing = $providerObjectType->isSubscription()
            ? Payment::query()->where('fib_subscription_id', $providerReference)->first()
            : Payment::query()->where('fib_payment_id', $providerReference)->first();

        $descriptor = $this->recoveryDescriptor($customer, $providerLookup);
        $status = PaymentStatus::from($providerLookup['mapped_local_status']);
        $reviewReason = $this->recoveryReason($customer, $descriptor, $possibleCandidates);
        $localReference = $this->recoveryLocalReference($providerObjectType, $providerReference);
        $idempotencyKey = $this->recoveryIdempotencyKey($providerObjectType, $providerReference);
        $amountIqd = max(0, (int) ($providerLookup['amount_iqd'] ?? 0));

        $payment = $existing ?? new Payment;
        $isNew = ! $payment->exists;

        $payment->forceFill([
            'uuid' => $payment->uuid ?: (string) Str::uuid(),
            'customer_id' => $payment->customer_id ?: (int) $customer->id,
            'provider' => PaymentProvider::FIB,
            'purchase_type' => $descriptor['purchase_type'],
            'payment_mode' => $descriptor['payment_mode'],
            'provider_object_type' => $providerObjectType,
            'status' => $status,
            'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
            'local_reference' => $payment->local_reference ?: $localReference,
            'idempotency_key' => $payment->idempotency_key ?: $idempotencyKey,
            'fib_payment_id' => $providerObjectType->isPayment() ? $providerReference : $payment->fib_payment_id,
            'fib_subscription_id' => $providerObjectType->isSubscription() ? $providerReference : $payment->fib_subscription_id,
            'readable_code' => $providerLookup['readable_code'] ?? $payment->readable_code,
            'provider_links' => $providerLookup['provider_links'] ?? $payment->provider_links,
            'amount' => $amountIqd,
            'currency' => (string) ($providerLookup['currency'] ?? 'IQD'),
            'original_amount_iqd' => $amountIqd,
            'discount_amount_iqd' => 0,
            'discounted_amount_iqd' => $amountIqd,
            'status_reason' => Str::limit((string) ($providerLookup['reason'] ?? $providerLookup['provider_status_raw'] ?? 'Recovered from provider lookup'), 190, ''),
            'mismatch_reason' => Str::limit($reviewReason, 255, ''),
            'declining_reason' => $providerObjectType->isPayment()
                ? Str::limit((string) ($providerLookup['reason'] ?? ''), 120, '')
                : null,
            'provider_status' => (string) ($providerLookup['provider_status_raw'] ?? ''),
            'provider_payment_status' => $providerObjectType->isPayment() ? (string) ($providerLookup['provider_status_raw'] ?? '') : $payment->provider_payment_status,
            'provider_subscription_status' => $providerObjectType->isSubscription() ? (string) ($providerLookup['provider_status_raw'] ?? '') : $payment->provider_subscription_status,
            'provider_interval' => $providerLookup['interval'] ?? $payment->provider_interval,
            'provider_trial_period' => $providerLookup['trial_period'] ?? $payment->provider_trial_period,
            'status_response' => $providerLookup['raw'] ?? $payment->status_response,
            'purchase_snapshot' => $descriptor['purchase_snapshot'],
            'meta' => array_merge((array) $payment->meta, [
                'recovery' => array_filter([
                    'type' => 'missing_local_provider_reference',
                    'provider_reference' => $providerReference,
                    'provider_object_type' => $providerObjectType->value,
                    'created_from_artisan' => true,
                    'created_by_command' => 'payments:inspect-fib',
                    'possible_local_candidate_ids' => $possibleCandidates->pluck('id')->values()->all(),
                    'lookup_reason' => 'Original local checkout record was missing or not linked to the provider id.',
                    'recorded_at' => now()->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null),
            ]),
            'purchasable_type' => $descriptor['purchasable_type'],
            'purchasable_id' => $descriptor['purchasable_id'],
            'valid_until' => $this->nullableCarbon($providerLookup['valid_until'] ?? null),
            'active_until' => $this->nullableCarbon($providerLookup['active_until'] ?? null),
            'last_payment_at' => $this->nullableCarbon($providerLookup['last_payment_at'] ?? null),
            'paid_at' => $this->nullableCarbon($providerLookup['paid_at'] ?? null),
            'review_required_at' => $payment->review_required_at ?? now(),
            'failed_at' => $this->recoveryFailureTimestamp($status, $providerLookup),
            'last_status_checked_at' => now(),
        ])->save();

        $this->events->record($payment, [
            'event_type' => 'manual_provider_recovery_created',
            'source' => 'artisan',
            'event_key' => 'manual-provider-recovery-created:'.$payment->id,
            'before_status' => $isNew ? null : ($payment->status?->value ?? null),
            'after_status' => $payment->status->value,
            'payload' => $providerLookup['raw'] ?? null,
            'meta' => [
                'command' => 'payments:inspect-fib',
                'customer_id' => (int) $customer->id,
                'provider_reference' => $providerReference,
                'provider_object_type' => $providerObjectType->value,
                'possible_local_candidate_ids' => $possibleCandidates->pluck('id')->values()->all(),
                'recovery_reason' => $reviewReason,
            ],
        ]);

        return $payment->fresh() ?? $payment;
    }

    /**
     * @return array{purchase_type:PurchaseType,payment_mode:PaymentMode,purchasable_type:?string,purchasable_id:?int,purchase_snapshot:array<string,mixed>}
     */
    protected function recoveryDescriptor(Customer $customer, array $providerLookup): array
    {
        $providerObjectType = PaymentProviderObjectType::from($providerLookup['provider_object_type']);
        $amountIqd = max(0, (int) ($providerLookup['amount_iqd'] ?? 0));
        $billingCycle = $providerObjectType->isSubscription()
            ? $this->billingCycleFromInterval((string) ($providerLookup['interval'] ?? ''))
            : null;
        $text = Str::lower(trim(implode(' ', array_filter([
            (string) ($providerLookup['title'] ?? ''),
            (string) ($providerLookup['description'] ?? ''),
        ]))));

        $serviceCandidates = $this->matchedServicePlans($amountIqd, $billingCycle, $text, $providerObjectType);
        $storageCandidates = $this->matchedStoragePlans($amountIqd, $billingCycle, $text, $providerObjectType);
        $addonCandidates = $providerObjectType->isPayment()
            ? $this->matchedAddonProducts($amountIqd)
            : collect();

        $service = $serviceCandidates->sortByDesc('score')->first();
        $storage = $storageCandidates->sortByDesc('score')->first();
        $addon = $addonCandidates->sortByDesc('score')->first();

        $winner = collect([
            'service' => $service,
            'storage' => $storage,
            'addon' => $addon,
        ])->filter()
            ->sortByDesc(fn (array $candidate): int => (int) $candidate['score'])
            ->first();

        if (is_array($winner) && ($winner['type'] ?? null) === 'service') {
            /** @var ServicePlan $plan */
            $plan = $winner['model'];
            $resolvedCycle = (string) ($winner['billing_cycle'] ?? $billingCycle ?? 'monthly');

            return [
                'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
                'payment_mode' => $providerObjectType->isSubscription() ? PaymentMode::RECURRING : PaymentMode::ONE_TIME,
                'purchasable_type' => ServicePlan::class,
                'purchasable_id' => (int) $plan->id,
                'purchase_snapshot' => array_filter([
                    'intended_plan' => [
                        'id' => (int) $plan->id,
                        'code' => (string) $plan->code,
                        'name' => (string) $plan->name,
                    ],
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'billing_cycle' => $resolvedCycle,
                    'amount_iqd' => $amountIqd,
                    'original_amount_iqd' => $amountIqd,
                    'checkout_context' => [
                        'current_service_plan_id' => (int) ($customer->currentServicePlan()?->id ?? 0) ?: null,
                        'current_service_plan_code' => $customer->currentServicePlan()?->code,
                        'current_service_plan_name' => $customer->currentServicePlan()?->name,
                    ],
                    'recovery' => [
                        'provider_reference_missing_local_row' => true,
                        'provider_object_type' => $providerObjectType->value,
                        'inference_confidence' => (int) ($winner['score'] ?? 0),
                    ],
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        if (is_array($winner) && ($winner['type'] ?? null) === 'storage') {
            /** @var StoragePlan $plan */
            $plan = $winner['model'];
            $resolvedCycle = (string) ($winner['billing_cycle'] ?? $billingCycle ?? 'monthly');

            return [
                'purchase_type' => PurchaseType::STORAGE_SUBSCRIPTION,
                'payment_mode' => $providerObjectType->isSubscription() ? PaymentMode::RECURRING : PaymentMode::ONE_TIME,
                'purchasable_type' => StoragePlan::class,
                'purchasable_id' => (int) $plan->id,
                'purchase_snapshot' => array_filter([
                    'intended_plan' => [
                        'id' => (int) $plan->id,
                        'code' => (string) $plan->code,
                        'name' => (string) $plan->name,
                    ],
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'billing_cycle' => $resolvedCycle,
                    'quota_mb' => (int) ($plan->quota_mb ?? 0),
                    'amount_iqd' => $amountIqd,
                    'original_amount_iqd' => $amountIqd,
                    'checkout_context' => [
                        'current_storage_plan_id' => (int) ($customer->currentStoragePlan()?->id ?? 0) ?: null,
                        'current_storage_plan_code' => $customer->currentStoragePlan()?->code,
                        'current_storage_plan_name' => $customer->currentStoragePlan()?->name,
                    ],
                    'recovery' => [
                        'provider_reference_missing_local_row' => true,
                        'provider_object_type' => $providerObjectType->value,
                        'inference_confidence' => (int) ($winner['score'] ?? 0),
                    ],
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        if (is_array($winner) && ($winner['type'] ?? null) === 'addon') {
            /** @var CreditProduct $product */
            $product = $winner['model'];

            return [
                'purchase_type' => PurchaseType::ADDON_CREDITS,
                'payment_mode' => PaymentMode::ONE_TIME,
                'purchasable_type' => CreditProduct::class,
                'purchasable_id' => (int) $product->id,
                'purchase_snapshot' => [
                    'code' => (string) $product->code,
                    'name' => (string) $product->name,
                    'credits_amount' => (int) ($product->credits_amount ?? 0),
                    'amount_iqd' => $amountIqd,
                    'original_amount_iqd' => $amountIqd,
                    'recovery' => [
                        'provider_reference_missing_local_row' => true,
                        'provider_object_type' => $providerObjectType->value,
                        'inference_confidence' => (int) ($winner['score'] ?? 0),
                    ],
                ],
            ];
        }

        if ($providerObjectType->isSubscription()) {
            return [
                'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
                'payment_mode' => PaymentMode::RECURRING,
                'purchasable_type' => null,
                'purchasable_id' => null,
                'purchase_snapshot' => array_filter([
                    'code' => 'provider-recovery',
                    'name' => trim((string) ($providerLookup['title'] ?? 'Recovered FIB subscription')) ?: 'Recovered FIB subscription',
                    'billing_cycle' => $billingCycle ?? 'monthly',
                    'amount_iqd' => $amountIqd,
                    'original_amount_iqd' => $amountIqd,
                    'checkout_context' => [
                        'current_service_plan_id' => (int) ($customer->currentServicePlan()?->id ?? 0) ?: null,
                        'current_service_plan_code' => $customer->currentServicePlan()?->code,
                        'current_service_plan_name' => $customer->currentServicePlan()?->name,
                    ],
                    'recovery' => [
                        'provider_reference_missing_local_row' => true,
                        'provider_object_type' => $providerObjectType->value,
                        'inference_confidence' => 0,
                    ],
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        return [
            'purchase_type' => PurchaseType::ADDON_CREDITS,
            'payment_mode' => PaymentMode::ONE_TIME,
            'purchasable_type' => null,
            'purchasable_id' => null,
            'purchase_snapshot' => [
                'code' => 'provider-recovery',
                'name' => 'Recovered FIB payment',
                'amount_iqd' => $amountIqd,
                'original_amount_iqd' => $amountIqd,
                'recovery' => [
                    'provider_reference_missing_local_row' => true,
                    'provider_object_type' => $providerObjectType->value,
                    'inference_confidence' => 0,
                ],
            ],
        ];
    }

    protected function recoveryReason(Customer $customer, array $descriptor, Collection $possibleCandidates): string
    {
        $base = 'Recovered from FIB provider reference but original local checkout record was missing. Manual admin review required before applying any billing change.';
        $currentPlanNote = match ($descriptor['purchase_type']) {
            PurchaseType::STORAGE_SUBSCRIPTION => $customer->currentStoragePlan()?->name
                ? ' Current storage plan: '.$customer->currentStoragePlan()?->name.'.'
                : '',
            PurchaseType::PLAN_SUBSCRIPTION => $customer->currentServicePlan()?->name
                ? ' Current service plan: '.$customer->currentServicePlan()?->name.'.'
                : '',
            default => '',
        };
        $candidateNote = $possibleCandidates->isNotEmpty()
            ? ' Possible local rows with missing provider linkage were found and left untouched for manual review.'
            : '';

        return trim($base.$currentPlanNote.$candidateNote);
    }

    protected function recoveryLocalReference(PaymentProviderObjectType $providerObjectType, string $providerReference): string
    {
        return sprintf(
            'FIB-REC-%s-%s',
            $providerObjectType->isSubscription() ? 'SUB' : 'PAY',
            strtoupper(substr(sha1($providerReference), 0, 16)),
        );
    }

    protected function recoveryIdempotencyKey(PaymentProviderObjectType $providerObjectType, string $providerReference): string
    {
        return 'fib-recovery:'.$providerObjectType->value.':'.sha1($providerReference);
    }

    protected function recoveryFailureTimestamp(PaymentStatus $status, array $providerLookup): ?CarbonInterface
    {
        if (! in_array($status, [PaymentStatus::FAILED, PaymentStatus::CANCELED, PaymentStatus::EXPIRED], true)) {
            return null;
        }

        return $this->nullableCarbon($providerLookup['declined_at'] ?? null) ?? now();
    }

    /**
     * @return Collection<int, array{type:string,model:object,score:int,billing_cycle:?string}>
     */
    protected function matchedServicePlans(
        int $amountIqd,
        ?string $billingCycle,
        string $text,
        PaymentProviderObjectType $providerObjectType,
    ): Collection {
        return ServicePlan::query()
            ->where('is_active', true)
            ->get()
            ->map(function (ServicePlan $plan) use ($amountIqd, $billingCycle, $text, $providerObjectType): ?array {
                $cycles = $providerObjectType->isSubscription()
                    ? array_filter([$billingCycle ?: 'monthly'])
                    : ['monthly', 'yearly', 'lifetime'];

                $bestScore = -1;
                $bestCycle = null;

                foreach ($cycles as $cycle) {
                    $comparisonCycle = $cycle === 'lifetime' ? 'monthly' : $cycle;

                    if ($plan->priceIqdForCycle($comparisonCycle) !== $amountIqd) {
                        continue;
                    }

                    $score = 10;

                    if ($text !== '' && str_contains($text, Str::lower((string) $plan->code))) {
                        $score += 100;
                    }

                    if ($text !== '' && str_contains($text, Str::lower((string) $plan->name))) {
                        $score += 120;
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestCycle = $cycle;
                    }
                }

                if ($bestScore < 0) {
                    return null;
                }

                return [
                    'type' => 'service',
                    'model' => $plan,
                    'score' => $bestScore,
                    'billing_cycle' => $bestCycle,
                ];
            })
            ->filter();
    }

    /**
     * @return Collection<int, array{type:string,model:object,score:int,billing_cycle:?string}>
     */
    protected function matchedStoragePlans(
        int $amountIqd,
        ?string $billingCycle,
        string $text,
        PaymentProviderObjectType $providerObjectType,
    ): Collection {
        return StoragePlan::query()
            ->where('is_active', true)
            ->get()
            ->map(function (StoragePlan $plan) use ($amountIqd, $billingCycle, $text, $providerObjectType): ?array {
                if ($plan->priceIqdAmount() !== $amountIqd) {
                    return null;
                }

                $score = 10;

                if ($text !== '' && str_contains($text, Str::lower((string) $plan->code))) {
                    $score += 100;
                }

                if ($text !== '' && str_contains($text, Str::lower((string) $plan->name))) {
                    $score += 120;
                }

                if ($text !== '' && str_contains($text, 'storage')) {
                    $score += 20;
                }

                return [
                    'type' => 'storage',
                    'model' => $plan,
                    'score' => $score,
                    'billing_cycle' => $providerObjectType->isSubscription() ? ($billingCycle ?? 'monthly') : 'monthly',
                ];
            })
            ->filter();
    }

    /**
     * @return Collection<int, array{type:string,model:object,score:int,billing_cycle:?string}>
     */
    protected function matchedAddonProducts(int $amountIqd): Collection
    {
        return CreditProduct::query()
            ->where('is_active', true)
            ->get()
            ->map(function (CreditProduct $product) use ($amountIqd): ?array {
                if ($product->priceIqdAmount() !== $amountIqd) {
                    return null;
                }

                return [
                    'type' => 'addon',
                    'model' => $product,
                    'score' => 10,
                    'billing_cycle' => null,
                ];
            })
            ->filter();
    }

    protected function billingCycleFromInterval(string $interval): ?string
    {
        return match (strtoupper(trim($interval))) {
            'P1Y' => 'yearly',
            'PT1H' => 'hourly',
            'P1M' => 'monthly',
            default => null,
        };
    }

    protected function nullableCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function relatedEventsForPayments(Collection $payments): Collection
    {
        if ($payments->isEmpty()) {
            return collect();
        }

        $paymentIds = $payments->pluck('id')->filter()->values()->all();
        $fibPaymentIds = $payments->pluck('fib_payment_id')->filter()->unique()->values()->all();
        $fibSubscriptionIds = $payments->pluck('fib_subscription_id')->filter()->unique()->values()->all();

        return PaymentEvent::query()
            ->where(function ($builder) use ($paymentIds, $fibPaymentIds, $fibSubscriptionIds) {
                if ($paymentIds !== []) {
                    $builder->whereIn('payment_id', $paymentIds);
                }

                if ($fibPaymentIds !== []) {
                    $builder->orWhereIn('fib_payment_id', $fibPaymentIds);
                }

                if ($fibSubscriptionIds !== []) {
                    $builder->orWhereIn('fib_subscription_id', $fibSubscriptionIds);
                }
            })
            ->orderBy('created_at')
            ->get();
    }

    protected function eventsForProviderLookup(array $providerLookup): Collection
    {
        if (! ($providerLookup['found'] ?? false)) {
            return collect();
        }

        $providerReference = (string) ($providerLookup['provider_reference'] ?? '');

        if ($providerReference === '') {
            return collect();
        }

        $column = ($providerLookup['provider_object_type'] ?? null) === PaymentProviderObjectType::SUBSCRIPTION->value
            ? 'fib_subscription_id'
            : 'fib_payment_id';

        return PaymentEvent::query()
            ->where($column, $providerReference)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function paymentSummaries(Collection $payments, ?Collection $events = null): array
    {
        $events ??= collect();

        return $payments
            ->map(function (Payment $payment) use ($events): array {
                return $this->paymentSummary($payment, $this->eventStatsForPayment($payment, $events));
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function eventStatsForPayment(Payment $payment, Collection $events): array
    {
        $matchedEvents = $events->filter(function (mixed $event) use ($payment): bool {
            if (! $event instanceof PaymentEvent) {
                return false;
            }

            if ((int) ($event->payment_id ?? 0) === (int) $payment->id) {
                return true;
            }

            if (filled($payment->fib_payment_id) && filled($event->fib_payment_id) && $event->fib_payment_id === $payment->fib_payment_id) {
                return true;
            }

            return filled($payment->fib_subscription_id)
                && filled($event->fib_subscription_id)
                && $event->fib_subscription_id === $payment->fib_subscription_id;
        })->values();

        $latestFailureEvent = $matchedEvents
            ->filter(fn (mixed $event): bool => $event instanceof PaymentEvent && $event->event_type === 'provider_status_sync_failed')
            ->sortByDesc(fn (PaymentEvent $event): int => (int) (($event->processed_at ?? $event->created_at)?->getTimestamp() ?? 0))
            ->first();

        return [
            'event_count' => $matchedEvents->count(),
            'sync_failure_event_count' => $matchedEvents->where('event_type', 'provider_status_sync_failed')->count(),
            'latest_sync_failure_event_at' => $latestFailureEvent?->processed_at?->toIso8601String()
                ?? $latestFailureEvent?->created_at?->toIso8601String(),
            'latest_sync_failure_event_note' => $latestFailureEvent instanceof PaymentEvent ? $this->eventNote($latestFailureEvent) : null,
        ];
    }

    protected function hasUnconfirmedProviderCheckout(array $report): bool
    {
        foreach ((array) ($report['local_rows'] ?? []) as $row) {
            if (is_array($row) && $this->rowLooksUnconfirmed($row)) {
                return true;
            }
        }

        $providerLookup = $report['provider_lookup'] ?? null;

        return is_array($providerLookup) && $this->lookupLooksUnconfirmed($providerLookup);
    }

    protected function rowLooksUnconfirmed(array $row): bool
    {
        if (($row['provider_object_type'] ?? null) !== PaymentProviderObjectType::SUBSCRIPTION->value) {
            return false;
        }

        if (filled($row['paid_at'] ?? null) || filled($row['last_payment_at'] ?? null)) {
            return false;
        }

        $providerStatus = strtoupper(trim((string) ($row['provider_subscription_status'] ?? $row['provider_status'] ?? '')));
        $localStatus = strtoupper(trim((string) ($row['local_status'] ?? '')));

        return in_array($providerStatus, ['DRAFT', 'PENDING', 'AWAITING_CUSTOMER_ACTION', 'UNPAID'], true)
            || in_array($localStatus, ['PENDING', 'AWAITING_CUSTOMER_ACTION'], true);
    }

    protected function lookupLooksUnconfirmed(array $lookup): bool
    {
        if (($lookup['provider_object_type'] ?? null) !== PaymentProviderObjectType::SUBSCRIPTION->value) {
            return false;
        }

        if (filled($lookup['paid_at'] ?? null) || filled($lookup['last_payment_at'] ?? null)) {
            return false;
        }

        $providerStatus = strtoupper(trim((string) ($lookup['provider_status_raw'] ?? '')));
        $mappedStatus = strtoupper(trim((string) ($lookup['mapped_local_status'] ?? '')));

        return in_array($providerStatus, ['DRAFT', 'PENDING', 'AWAITING_CUSTOMER_ACTION', 'UNPAID'], true)
            || in_array($mappedStatus, ['PENDING', 'AWAITING_CUSTOMER_ACTION'], true);
    }

    protected function collectSuspiciousConditions(Collection $payments, Collection $events): array
    {
        $conditions = [];

        foreach ($payments as $payment) {
            if (! $payment instanceof Payment) {
                continue;
            }

            if ($payment->status === PaymentStatus::PAID && ! $payment->isApplied()) {
                $conditions[] = sprintf('paid but not fulfilled (payment_id=%d)', (int) $payment->id);
            }

            if ($payment->status === PaymentStatus::PAID && $payment->requiresReview()) {
                $conditions[] = sprintf('paid but requires review (payment_id=%d)', (int) $payment->id);
            }

            if (filled($payment->mismatch_reason)) {
                $conditions[] = sprintf('plan mismatch/review reason present (payment_id=%d)', (int) $payment->id);
            }

            if ($payment->isProviderPaymentObject() && ! filled($payment->fib_payment_id)) {
                $conditions[] = sprintf('missing provider payment id (payment_id=%d)', (int) $payment->id);
            }

            if ($payment->isProviderSubscriptionObject() && ! filled($payment->fib_subscription_id)) {
                $conditions[] = sprintf('missing provider subscription id (payment_id=%d)', (int) $payment->id);
            }

            if ((int) $payment->customer_id <= 0) {
                $conditions[] = sprintf('missing customer id (payment_id=%d)', (int) $payment->id);
            }

            $providerStatus = strtoupper(trim((string) ($payment->provider_subscription_status ?: $payment->provider_status)));

            if ($payment->isProviderSubscriptionObject()
                && in_array($providerStatus, ['ACTIVE', 'SUBSCRIBED'], true)
                && $payment->status === PaymentStatus::PAID
                && ! $payment->paid_at instanceof CarbonInterface
                && ! $payment->last_payment_at instanceof CarbonInterface) {
                $conditions[] = sprintf('provider ACTIVE treated as paid without clear payment evidence (payment_id=%d)', (int) $payment->id);
            }
        }

        if ($events->contains(fn (mixed $event): bool => $event instanceof PaymentEvent && $event->event_type === 'callback_orphaned')) {
            $conditions[] = 'callback orphaned event detected';
        }

        if ($payments->isEmpty()) {
            $conditions[] = 'no local rows found';
        }

        return array_values(array_unique($conditions));
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentSummary(Payment $payment, array $eventStats = []): array
    {
        $snapshot = $payment->snapshot();
        $statusResponse = is_array($payment->status_response) ? $payment->status_response : [];
        $createResponse = is_array($payment->create_response) ? $payment->create_response : [];
        $callbackPayload = is_array($payment->callback_payload) ? $payment->callback_payload : [];
        $latestSyncFailure = is_array(data_get($payment->meta, 'latest_sync_failure'))
            ? (array) data_get($payment->meta, 'latest_sync_failure')
            : [];

        return [
            'payment_id' => (int) $payment->id,
            'customer_id' => (int) $payment->customer_id,
            'fib_payment_id' => $payment->fib_payment_id,
            'fib_subscription_id' => $payment->fib_subscription_id,
            'local_reference' => $payment->local_reference,
            'purchase_type' => (string) ($payment->purchase_type?->value ?? $payment->purchase_type),
            'payment_mode' => (string) ($payment->payment_mode?->value ?? $payment->payment_mode),
            'provider_object_type' => (string) ($payment->provider_object_type?->value ?? $payment->provider_object_type),
            'readable_code' => $this->firstStringValue(
                $payment->readable_code,
                data_get($statusResponse, 'readableCode'),
                data_get($createResponse, 'readableCode'),
            ),
            'intended_plan_code' => (string) data_get($snapshot, 'intended_plan.code', data_get($snapshot, 'code', '')),
            'intended_plan_name' => (string) data_get($snapshot, 'intended_plan.name', data_get($snapshot, 'name', '')),
            'checkout_current_plan_code' => (string) data_get(
                $snapshot,
                'checkout_context.current_service_plan_code',
                data_get($snapshot, 'checkout_context.current_storage_plan_code', '')
            ),
            'checkout_current_plan_name' => (string) data_get(
                $snapshot,
                'checkout_context.current_service_plan_name',
                data_get($snapshot, 'checkout_context.current_storage_plan_name', '')
            ),
            'provider_title' => $this->firstStringValue(
                data_get($statusResponse, 'title'),
                data_get($createResponse, 'title'),
                data_get($snapshot, 'name'),
            ),
            'provider_description' => $this->firstStringValue(
                data_get($statusResponse, 'description'),
                data_get($createResponse, 'description'),
            ),
            'amount' => (int) round((float) ($payment->amount ?? 0)),
            'currency' => (string) ($payment->currency ?? ''),
            'provider_status' => (string) ($payment->providerStatusLabel() ?? ''),
            'provider_payment_status' => (string) ($payment->provider_payment_status ?? ''),
            'provider_subscription_status' => (string) ($payment->provider_subscription_status ?? ''),
            'status_response_status' => $this->firstStringValue(data_get($statusResponse, 'status')),
            'callback_status' => $this->firstStringValue(data_get($callbackPayload, 'status')),
            'local_status' => (string) ($payment->status?->value ?? $payment->status),
            'internal_status' => (string) ($payment->internal_status?->value ?? ''),
            'status_reason' => (string) ($payment->status_reason ?? ''),
            'mismatch_reason' => (string) ($payment->mismatch_reason ?? ''),
            'event_count' => (int) ($eventStats['event_count'] ?? 0),
            'sync_failure_event_count' => (int) ($eventStats['sync_failure_event_count'] ?? 0),
            'latest_sync_failure_count' => (int) data_get($payment->meta, 'latest_sync_failure_count', 0),
            'latest_sync_failure_message' => $this->firstStringValue(
                data_get($latestSyncFailure, 'safe_message'),
                data_get($latestSyncFailure, 'display_error'),
                (string) ($eventStats['latest_sync_failure_event_note'] ?? ''),
            ),
            'latest_sync_failure_event_at' => $eventStats['latest_sync_failure_event_at'] ?? null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'review_required_at' => $payment->review_required_at?->toIso8601String(),
            'fulfilled_at' => $payment->fulfilled_at?->toIso8601String(),
            'valid_until' => $payment->valid_until?->toIso8601String(),
            'active_until' => $payment->active_until?->toIso8601String(),
            'last_payment_at' => $payment->last_payment_at?->toIso8601String(),
            'last_callback_received_at' => $payment->last_callback_received_at?->toIso8601String(),
            'last_status_checked_at' => $payment->last_status_checked_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function eventSummary(PaymentEvent $event): array
    {
        return [
            'event_id' => (int) $event->id,
            'payment_id' => $event->payment_id !== null ? (int) $event->payment_id : null,
            'event_type' => (string) $event->event_type,
            'source' => (string) $event->source,
            'fib_payment_id' => $event->fib_payment_id,
            'fib_subscription_id' => $event->fib_subscription_id,
            'before_status' => $event->before_status,
            'after_status' => $event->after_status,
            'response_code' => $event->response_code !== null ? (int) $event->response_code : null,
            'note' => $this->eventNote($event),
            'payload' => $event->payload,
            'meta' => $event->meta,
            'processed_at' => $event->processed_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    protected function eventNote(PaymentEvent $event): string
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $meta = is_array($event->meta) ? $event->meta : [];

        foreach ([
            data_get($payload, 'safe_message'),
            data_get($payload, 'display_error'),
            trim(implode(' ', array_filter([
                data_get($payload, 'fib_error_code'),
                data_get($payload, 'fib_error_title'),
            ]))),
            data_get($meta, 'reason'),
            data_get($meta, 'recovery_reason'),
            data_get($payload, 'status'),
        ] as $candidate) {
            $value = $this->firstStringValue($candidate);

            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    protected function firstStringValue(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterSummary(
        ?Customer $customer,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string $fibPaymentId = null,
        ?string $fibSubscriptionId = null,
        ?string $providerReference = null,
    ): array {
        return array_filter([
            'customer_id' => $customer?->id,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'fib_payment_id' => $fibPaymentId,
            'fib_subscription_id' => $fibSubscriptionId,
            'provider_reference' => $providerReference,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function renderReport(array $report): int
    {
        $exitCode = (int) ($report['exit_code'] ?? self::SUCCESS);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->newLine();
        $this->info('FIB INSPECTION');
        $this->table(
            ['Key', 'Value'],
            collect((array) ($report['filters'] ?? []))
                ->map(fn (mixed $value, string $key): array => [$key, $this->stringifyReportValue($value)])
                ->values()
                ->all(),
        );

        $environment = collect((array) ($report['environment'] ?? []))->filter();

        if ($environment->isNotEmpty()) {
            $this->newLine();
            $this->info('FIB ENVIRONMENT');
            $this->table(
                ['Key', 'Value'],
                $environment->map(fn (mixed $value, string $key): array => [$key, $this->stringifyReportValue($value)])->values()->all(),
            );
        }

        $warnings = collect((array) ($report['warnings'] ?? []))->filter();

        if ($warnings->isNotEmpty()) {
            $this->newLine();
            $this->warn('WARNINGS');

            foreach ($warnings as $warning) {
                $this->line('- '.$warning);
            }
        }

        $localRows = collect((array) ($report['local_rows'] ?? []));

        if ($localRows->isNotEmpty()) {
            $this->newLine();
            $this->info('LOCAL FIB ROWS');
            $this->table([
                'payment_id',
                'purchase_type',
                'payment_mode',
                'provider_object_type',
                'intended_plan_code',
                'checkout_current_plan_code',
                'fib_payment_id',
                'fib_subscription_id',
                'readable_code',
                'amount',
                'currency',
                'provider_status',
                'local_status',
                'internal_status',
                'latest_sync_failure_count',
                'latest_sync_failure_message',
                'mismatch_reason',
                'paid_at',
                'last_payment_at',
                'fulfilled_at',
                'last_status_checked_at',
                'created_at',
            ], $localRows->map(fn (array $row): array => [
                $row['payment_id'] ?? '',
                $row['purchase_type'] ?? '',
                $row['payment_mode'] ?? '',
                $row['provider_object_type'] ?? '',
                $row['intended_plan_code'] ?? '',
                $row['checkout_current_plan_code'] ?? '',
                $row['fib_payment_id'] ?? '',
                $row['fib_subscription_id'] ?? '',
                $row['readable_code'] ?? '',
                $row['amount'] ?? '',
                $row['currency'] ?? '',
                $row['provider_status'] ?? '',
                $row['local_status'] ?? '',
                $row['internal_status'] ?? '',
                $row['latest_sync_failure_count'] ?? '',
                $row['latest_sync_failure_message'] ?? '',
                $row['mismatch_reason'] ?? '',
                $row['paid_at'] ?? '',
                $row['last_payment_at'] ?? '',
                $row['fulfilled_at'] ?? '',
                $row['last_status_checked_at'] ?? '',
                $row['created_at'] ?? '',
            ])->all());
        }

        $events = collect((array) ($report['payment_events'] ?? []));

        if ($events->isNotEmpty()) {
            $this->newLine();
            $this->info('PAYMENT EVENTS');
            $this->table([
                'event_id',
                'payment_id',
                'event_type',
                'source',
                'response_code',
                'note',
                'processed_at',
            ], $events->map(fn (array $row): array => [
                $row['event_id'] ?? '',
                $row['payment_id'] ?? '',
                $row['event_type'] ?? '',
                $row['source'] ?? '',
                $row['response_code'] ?? '',
                $row['note'] ?? '',
                $row['processed_at'] ?? '',
            ])->all());
        }

        $providerLookup = $report['provider_lookup'] ?? null;

        if (is_array($providerLookup) && $providerLookup !== []) {
            $this->newLine();
            $this->info('PROVIDER LOOKUP');
            $rows = collect($providerLookup)
                ->map(fn (mixed $value, string $key): array => [$key, $this->stringifyReportValue($value)])
                ->values()
                ->all();
            $this->table(['Key', 'Value'], $rows);
        }

        $candidates = collect((array) ($report['possible_local_candidates'] ?? []));

        if ($candidates->isNotEmpty()) {
            $this->newLine();
            $this->info('POSSIBLE LOCAL CANDIDATES');
            $this->table([
                'payment_id',
                'purchase_type',
                'payment_mode',
                'provider_object_type',
                'local_reference',
                'provider_status',
                'internal_status',
                'created_at',
            ], $candidates->map(fn (array $row): array => [
                $row['payment_id'] ?? '',
                $row['purchase_type'] ?? '',
                $row['payment_mode'] ?? '',
                $row['provider_object_type'] ?? '',
                $row['local_reference'] ?? '',
                $row['provider_status'] ?? '',
                $row['internal_status'] ?? '',
                $row['created_at'] ?? '',
            ])->all());
        }

        $suspicious = collect((array) ($report['suspicious_conditions'] ?? []))->filter();

        if ($suspicious->isNotEmpty()) {
            $this->newLine();
            $this->warn('SUSPICIOUS CONDITIONS');

            foreach ($suspicious as $condition) {
                $this->line('- '.$condition);
            }
        }

        $recoveryAction = $report['recovery_action'] ?? null;

        if (is_array($recoveryAction) && $recoveryAction !== []) {
            $this->newLine();
            $this->info('RECOVERY ACTION');
            $this->table(
                ['Key', 'Value'],
                collect($recoveryAction)->map(fn (mixed $value, string $key): array => [$key, $this->stringifyReportValue($value)])->values()->all(),
            );
        }

        return $exitCode;
    }
}

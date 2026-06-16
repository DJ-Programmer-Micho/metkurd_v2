<?php

namespace App\Services\Billing;

use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Coupon;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Services\Coupons\CouponLifecycleService;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncProviderSubscriptionLifecycle
{
    public function __construct(
        protected FibSubscriptionService $fibSubscriptions,
        protected PaymentEventRecorder $events,
        protected CouponLifecycleService $couponLifecycle,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
        protected CreditService $credits,
    ) {}

    public function handle(Payment $payment, string $source = 'provider_status_sync'): void
    {
        $payment = $payment->fresh() ?? $payment;

        if (! $payment->isProviderSubscriptionObject() || ! $payment->isFulfilled()) {
            return;
        }

        match ($payment->purchase_type) {
            PurchaseType::PLAN_SUBSCRIPTION => $this->syncServiceSubscription($payment, $source),
            PurchaseType::STORAGE_SUBSCRIPTION => $this->syncStorageSubscription($payment, $source),
            default => null,
        };
    }

    protected function syncServiceSubscription(Payment $payment, string $source): void
    {
        DB::transaction(function () use ($payment, $source) {
            /** @var CustomerServiceSubscription|null $subscription */
            $subscription = CustomerServiceSubscription::query()
                ->lockForUpdate()
                ->where('payment_id', $payment->id)
                ->latest('id')
                ->first();

            if (! $subscription instanceof CustomerServiceSubscription) {
                return;
            }

            $this->applyLifecycleState($subscription, $payment, 'service_subscription', $source);
        }, 3);
    }

    protected function syncStorageSubscription(Payment $payment, string $source): void
    {
        DB::transaction(function () use ($payment, $source) {
            /** @var CustomerStorageSubscription|null $subscription */
            $subscription = CustomerStorageSubscription::query()
                ->lockForUpdate()
                ->where('payment_id', $payment->id)
                ->latest('id')
                ->first();

            if (! $subscription instanceof CustomerStorageSubscription) {
                return;
            }

            $this->applyLifecycleState($subscription, $payment, 'storage_subscription', $source);
        }, 3);
    }

    protected function applyLifecycleState(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        Payment $payment,
        string $eventPrefix,
        string $source,
    ): void {
        $providerStatus = $this->fibSubscriptions->normalizeProviderStatus(
            $payment->provider_subscription_status ?: $payment->provider_status
        );
        $previousProviderStatus = (string) data_get($subscription->meta, 'provider_status', '');
        $previousPeriodEndsAt = data_get($subscription->meta, 'period_ends_at');
        $billingCycle = strtolower((string) data_get(
            $payment->purchase_snapshot,
            'billing_cycle',
            data_get($subscription->meta, 'billing_cycle', 'monthly')
        ));
        $periodEndsAt = $this->resolvePeriodEnd($payment, (array) ($subscription->meta ?? []), $billingCycle);
        $renewalDetected = $this->renewalDetected($subscription, $payment);
        $renewalCycleKey = $this->renewalCycleKey($payment);
        $shouldAutoRenew = $this->shouldAutoRenew($providerStatus);
        $shouldEndNow = $this->shouldEndNow($providerStatus, $periodEndsAt);
        $wasAutoRenewing = (bool) ($subscription->auto_renew ?? false);
        $previousStatus = (string) ($subscription->status ?? 'active');
        $effectiveEndsAt = $shouldAutoRenew ? null : $periodEndsAt;
        $discountCyclesConsumed = $this->nextDiscountCycleCount(
            $subscription,
            (int) ($subscription->discount_cycles_consumed ?? 0),
            $renewalDetected,
        );
        $meta = (array) ($subscription->meta ?? []);
        $meta['billing_cycle'] = $billingCycle;
        $meta['provider_active_until'] = $periodEndsAt?->toIso8601String();
        $meta['period_ends_at'] = $periodEndsAt?->toIso8601String();
        $meta['provider_last_payment_at'] = $payment->last_payment_at?->toIso8601String();
        $meta['provider_status'] = $providerStatus;
        $meta['provider_lifecycle_synced_at'] = now()->toIso8601String();
        $meta['provider_lifecycle_sync_source'] = $source;
        $meta['discount_cycles_consumed'] = $discountCyclesConsumed;
        $renewalApplied = false;
        $renewalAlreadyApplied = false;
        $cancelSource = $this->resolveCancelSource(
            $subscription,
            $payment,
            $source,
            $providerStatus,
            $shouldAutoRenew,
            $shouldEndNow,
            $wasAutoRenewing,
        );

        if ($cancelSource !== null) {
            $meta['cancel_source'] = $cancelSource;
        } else {
            unset($meta['cancel_source']);
        }

        $subscription->forceFill([
            'status' => $shouldEndNow ? 'ended' : 'active',
            'cycle_ends_on' => $periodEndsAt?->toDateString(),
            'next_renewal_on' => $periodEndsAt?->toDateString(),
            'auto_renew' => $shouldAutoRenew,
            'ends_at' => $effectiveEndsAt,
            'discount_cycles_consumed' => $discountCyclesConsumed,
            'canceled_at' => $shouldAutoRenew
                ? null
                : ($subscription->canceled_at ?? now()),
            'meta' => $meta,
        ])->save();

        $paymentMeta = (array) ($payment->meta ?? []);
        $paymentMeta['subscription_lifecycle'] = array_filter([
            'status' => $subscription->status,
            'auto_renew' => (bool) $subscription->auto_renew,
            'period_ends_at' => $periodEndsAt?->toIso8601String(),
            'provider_status' => $providerStatus,
            'provider_last_payment_at' => $payment->last_payment_at?->toIso8601String(),
            'provider_cycle_key' => $renewalCycleKey,
            'cancel_source' => $cancelSource,
            'synced_at' => now()->toIso8601String(),
            'sync_source' => $source,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $payment->forceFill(['meta' => $paymentMeta])->save();

        if ($renewalDetected
            && $subscription instanceof CustomerServiceSubscription
            && $payment->last_payment_at instanceof CarbonInterface
            && $renewalCycleKey !== null) {
            $renewalResult = $this->credits->applyProviderRenewalCycle($subscription, [
                'provider_cycle_key' => $renewalCycleKey,
                'provider_last_payment_at' => $payment->last_payment_at->toIso8601String(),
                'billing_cycle' => $billingCycle,
                'cycle_started_at' => $payment->last_payment_at,
                'cycle_ends_at' => $periodEndsAt,
                'source' => $source,
                'source_type' => 'subscription_refill',
                'payment_id' => $payment->id,
                'provider_reference' => $payment->providerReference(),
            ]);

            $renewalApplied = (bool) ($renewalResult['applied'] ?? false);
            $renewalAlreadyApplied = (bool) ($renewalResult['already_applied'] ?? false);
            $subscription = $subscription->fresh() ?? $subscription;
        }

        if ($renewalDetected) {
            $renewalEvent = $this->events->record($payment, [
                'event_type' => $eventPrefix.'_renewed',
                'source' => $source,
                'event_key' => $this->lifecycleEventKey(
                    $payment,
                    $eventPrefix.'_renewed',
                    [
                        $payment->last_payment_at?->toIso8601String(),
                        $periodEndsAt?->toIso8601String(),
                    ]
                ),
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'previous_provider_status' => $previousProviderStatus !== '' ? $previousProviderStatus : null,
                    'previous_period_ends_at' => $previousPeriodEndsAt,
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                    'provider_cycle_key' => $renewalCycleKey,
                    'renewal_applied' => $renewalApplied,
                    'renewal_already_applied' => $renewalAlreadyApplied,
                    'sync_source' => $source,
                ],
            ]);

            $this->notifyLifecycleEvent(
                $renewalEvent,
                $payment,
                __('FIB subscription renewed'),
                [
                    'Event' => 'renewed',
                    'Provider status' => $providerStatus,
                    'Previous period end' => $this->valueOrDash($previousPeriodEndsAt),
                    'New period end' => $this->valueOrDash($periodEndsAt?->toIso8601String()),
                    'Last payment at' => $this->valueOrDash($payment->last_payment_at?->toIso8601String()),
                    'Renewal cycle key' => $this->valueOrDash($renewalCycleKey),
                    'Renewal applied' => $renewalApplied ? 'yes' : ($renewalAlreadyApplied ? 'already applied' : 'no'),
                    'Sync source' => $source,
                ],
            );
        }

        if ($wasAutoRenewing && ! $shouldAutoRenew && ! $shouldEndNow) {
            $cancelAtPeriodEndEvent = $this->events->record($payment, [
                'event_type' => $eventPrefix.'_cancel_at_period_end',
                'source' => $source,
                'event_key' => $this->lifecycleEventKey(
                    $payment,
                    $eventPrefix.'_cancel_at_period_end',
                    [
                        $providerStatus,
                        $periodEndsAt?->toIso8601String(),
                        $cancelSource,
                    ]
                ),
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                    'cancel_source' => $cancelSource,
                    'sync_source' => $source,
                ],
            ]);

            $this->notifyLifecycleEvent(
                $cancelAtPeriodEndEvent,
                $payment,
                __('FIB subscription cancellation scheduled'),
                [
                    'Event' => 'cancel_at_period_end',
                    'Provider status' => $providerStatus,
                    'Cancel source' => $this->valueOrDash($cancelSource),
                    'Service until' => $this->valueOrDash($periodEndsAt?->toIso8601String()),
                    'Sync source' => $source,
                ],
            );
        }

        if ($previousStatus !== 'ended' && $shouldEndNow) {
            $endedEvent = $this->events->record($payment, [
                'event_type' => $eventPrefix.'_ended',
                'source' => $source,
                'event_key' => $this->lifecycleEventKey(
                    $payment,
                    $eventPrefix.'_ended',
                    [
                        $providerStatus,
                        $periodEndsAt?->toIso8601String(),
                        $cancelSource,
                    ]
                ),
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                    'cancel_source' => $cancelSource,
                    'sync_source' => $source,
                ],
            ]);

            $this->notifyLifecycleEvent(
                $endedEvent,
                $payment,
                __('FIB subscription ended'),
                [
                    'Event' => 'ended',
                    'Provider status' => $providerStatus,
                    'Cancel source' => $this->valueOrDash($cancelSource),
                    'Ended at period end' => $this->valueOrDash($periodEndsAt?->toIso8601String()),
                    'Sync source' => $source,
                ],
            );
        }
    }

    protected function renewalDetected(Model $subscription, Payment $payment): bool
    {
        if (! $payment->last_payment_at instanceof CarbonInterface) {
            return false;
        }

        $lastSyncedPaymentAt = data_get($subscription->meta, 'provider_last_payment_at');

        if (! is_scalar($lastSyncedPaymentAt) || trim((string) $lastSyncedPaymentAt) === '') {
            return true;
        }

        try {
            return $payment->last_payment_at->greaterThan(Carbon::parse((string) $lastSyncedPaymentAt));
        } catch (\Throwable) {
            return true;
        }
    }

    protected function renewalCycleKey(Payment $payment): ?string
    {
        return $payment->providerRecurringCycleKey();
    }

    protected function resolvePeriodEnd(Payment $payment, array $subscriptionMeta, string $billingCycle): ?CarbonInterface
    {
        if ($payment->active_until instanceof CarbonInterface) {
            return Carbon::instance($payment->active_until);
        }

        foreach (['period_ends_at', 'provider_active_until'] as $key) {
            $value = data_get($subscriptionMeta, $key);

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            try {
                return Carbon::parse((string) $value);
            } catch (\Throwable) {
                continue;
            }
        }

        if (! $payment->last_payment_at instanceof CarbonInterface) {
            return null;
        }

        $startAt = Carbon::instance($payment->last_payment_at);

        return match ($billingCycle) {
            'yearly' => $startAt->copy()->addYear(),
            'hourly' => $startAt->copy()->addHour(),
            default => $startAt->copy()->addMonth(),
        };
    }

    protected function shouldAutoRenew(?string $providerStatus): bool
    {
        return in_array($providerStatus, ['ACTIVE', 'PAID', 'SUBSCRIBED'], true);
    }

    protected function shouldEndNow(?string $providerStatus, ?CarbonInterface $periodEndsAt): bool
    {
        if ($providerStatus === null) {
            return false;
        }

        if ($this->fibSubscriptions->isClosedProviderStatus($providerStatus)) {
            return ! $periodEndsAt instanceof CarbonInterface || ! $periodEndsAt->isFuture();
        }

        if (in_array($providerStatus, ['UNPAID', 'PENDING', 'CREATED', 'INITIATED'], true)) {
            return ! $periodEndsAt instanceof CarbonInterface || ! $periodEndsAt->isFuture();
        }

        return false;
    }

    protected function nextDiscountCycleCount(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        int $currentCount,
        bool $renewalDetected,
    ): int {
        $currentCount = max(0, $currentCount);

        if (! $renewalDetected || ! $subscription->coupon_id) {
            return $currentCount;
        }

        $coupon = Coupon::query()->find($subscription->coupon_id);

        if (! $coupon instanceof Coupon) {
            return $currentCount;
        }

        $nextCycleIndex = $currentCount + 1;

        return $this->couponLifecycle->appliesToCycle($coupon, $nextCycleIndex)
            ? $nextCycleIndex
            : $currentCount;
    }

    protected function resolveCancelSource(
        CustomerServiceSubscription|CustomerStorageSubscription $subscription,
        Payment $payment,
        string $source,
        ?string $providerStatus,
        bool $shouldAutoRenew,
        bool $shouldEndNow,
        bool $wasAutoRenewing,
    ): ?string {
        $existing = trim((string) data_get($subscription->meta, 'cancel_source', ''));

        if ($shouldAutoRenew) {
            return null;
        }

        if ($existing !== '' && ! $wasAutoRenewing) {
            return $existing;
        }

        $providerCancellationRequested = (bool) data_get($subscription->meta, 'provider_cancellation.requested_at');

        if ($providerCancellationRequested || $source === 'cancel_confirmation') {
            return 'customer_web';
        }

        if ($shouldEndNow && in_array($providerStatus, ['FAILED', 'UNPAID', 'DECLINED', 'REJECTED', 'EXPIRED', 'INACTIVE', 'ENDED'], true)) {
            return 'renewal_failed';
        }

        if ($payment->status->value === 'canceled') {
            return 'customer_web';
        }

        return 'provider_app';
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    protected function lifecycleEventKey(Payment $payment, string $eventType, array $parts = []): string
    {
        $fingerprint = collect($parts)
            ->map(static fn (mixed $value): string => trim((string) ($value ?? '')))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->implode('|');

        return 'subscription-lifecycle:'.$eventType.':'.$payment->id.':'.sha1($fingerprint);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function notifyLifecycleEvent(PaymentEvent $event, Payment $payment, string $title, array $details): void
    {
        if (! $event->wasRecentlyCreated) {
            return;
        }

        $customer = $payment->customer()->first();
        $eventDetails = [
            'Type' => $payment->purchase_type === PurchaseType::STORAGE_SUBSCRIPTION
                ? 'storage_subscription'
                : 'service_subscription',
            'Customer ID' => $payment->customer_id,
            'Username' => $customer?->username,
            'Local status' => $payment->status->value,
            'Provider ref' => $payment->providerReference(),
            'Payment UUID' => $payment->uuid,
        ];

        $this->telegramLifecycleNotifier->send(
            $title,
            array_merge($eventDetails, $details),
            'Subscription lifecycle'
        );
    }

    protected function valueOrDash(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : 'N/A';
    }
}

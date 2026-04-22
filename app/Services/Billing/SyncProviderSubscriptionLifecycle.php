<?php

namespace App\Services\Billing;

use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Coupon;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Services\Coupons\CouponLifecycleService;
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
    ) {
    }

    public function handle(Payment $payment): void
    {
        $payment = $payment->fresh() ?? $payment;

        if (! $payment->isProviderSubscriptionObject() || ! $payment->isFulfilled()) {
            return;
        }

        match ($payment->purchase_type) {
            PurchaseType::PLAN_SUBSCRIPTION => $this->syncServiceSubscription($payment),
            PurchaseType::STORAGE_SUBSCRIPTION => $this->syncStorageSubscription($payment),
            default => null,
        };
    }

    protected function syncServiceSubscription(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var CustomerServiceSubscription|null $subscription */
            $subscription = CustomerServiceSubscription::query()
                ->lockForUpdate()
                ->where('payment_id', $payment->id)
                ->latest('id')
                ->first();

            if (! $subscription instanceof CustomerServiceSubscription) {
                return;
            }

            $this->applyLifecycleState($subscription, $payment, 'service_subscription');
        }, 3);
    }

    protected function syncStorageSubscription(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var CustomerStorageSubscription|null $subscription */
            $subscription = CustomerStorageSubscription::query()
                ->lockForUpdate()
                ->where('payment_id', $payment->id)
                ->latest('id')
                ->first();

            if (! $subscription instanceof CustomerStorageSubscription) {
                return;
            }

            $this->applyLifecycleState($subscription, $payment, 'storage_subscription');
        }, 3);
    }

    protected function applyLifecycleState(CustomerServiceSubscription|CustomerStorageSubscription $subscription, Payment $payment, string $eventPrefix): void
    {
        $providerStatus = $this->fibSubscriptions->normalizeProviderStatus(
            $payment->provider_subscription_status ?: $payment->provider_status
        );
        $billingCycle = strtolower((string) data_get(
            $payment->purchase_snapshot,
            'billing_cycle',
            data_get($subscription->meta, 'billing_cycle', 'monthly')
        ));
        $periodEndsAt = $this->resolvePeriodEnd($payment, (array) ($subscription->meta ?? []), $billingCycle);
        $renewalDetected = $this->renewalDetected($subscription, $payment);
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
        $meta['discount_cycles_consumed'] = $discountCyclesConsumed;

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

        if ($renewalDetected) {
            $this->events->record($payment, [
                'event_type' => $eventPrefix . '_renewed',
                'source' => 'provider_status_sync',
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                ],
            ]);
        }

        if ($wasAutoRenewing && ! $shouldAutoRenew && ! $shouldEndNow) {
            $this->events->record($payment, [
                'event_type' => $eventPrefix . '_cancel_at_period_end',
                'source' => 'provider_status_sync',
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                ],
            ]);
        }

        if ($previousStatus !== 'ended' && $shouldEndNow) {
            $this->events->record($payment, [
                'event_type' => $eventPrefix . '_ended',
                'source' => 'provider_status_sync',
                'before_status' => $payment->status->value,
                'after_status' => $payment->status->value,
                'meta' => [
                    'provider_status' => $providerStatus,
                    'period_ends_at' => $periodEndsAt?->toIso8601String(),
                ],
            ]);
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

    protected function resolvePeriodEnd(Payment $payment, array $subscriptionMeta, string $billingCycle): Carbon
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

        $startAt = $payment->last_payment_at instanceof CarbonInterface
            ? Carbon::instance($payment->last_payment_at)
            : now();

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
}

<?php

namespace App\Services\Coupons;

use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Enums\CouponDiscountType;
use App\Enums\CouponDurationType;
use App\Enums\CouponRedemptionStatus;
use App\Enums\CouponRedemptionType;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function __construct(
        protected CouponLifecycleService $lifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(?string $code, CouponContext $context): array
    {
        $coupon = $this->findByCodeOrFail($code);
        $this->assertEligible($coupon, $context, checkUsageLimits: true);

        return $this->quote($coupon, $context);
    }

    /**
     * @return array{coupon:Coupon,pricing:array<string, mixed>}|null
     */
    public function resolveForCheckout(?string $code, CouponContext $context): ?array
    {
        $normalized = $this->normalizeCode($code);

        if ($normalized === '') {
            return null;
        }

        $coupon = $this->findByCodeOrFail($normalized);
        $this->assertEligible($coupon, $context, checkUsageLimits: true);

        return [
            'coupon' => $coupon,
            'pricing' => $this->quote($coupon, $context),
        ];
    }

    public function hasEligibleCouponSupport(CouponContext $context): bool
    {
        return Coupon::query()
            ->where('is_active', true)
            ->whereIn('target_type', [
                $context->purchaseType->value,
                'all',
            ])
            ->orderBy('id')
            ->get()
            ->contains(function (Coupon $coupon) use ($context): bool {
                try {
                    $this->assertEligible($coupon, $context, checkUsageLimits: true);

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(Coupon $coupon, CouponContext $context): array
    {
        $originalAmount = max(0, $context->originalAmountIqd);
        $discountAmount = $this->discountAmount($coupon, $originalAmount);
        $finalAmount = max(0, $originalAmount - $discountAmount);

        if ($finalAmount <= 0) {
            throw ValidationException::withMessages([
                'coupon' => __('This coupon would reduce the checkout to zero, and zero-value FIB checkouts are not supported yet.'),
            ]);
        }

        return [
            'coupon_id' => $coupon->id,
            'code' => (string) $coupon->code,
            'name' => (string) $coupon->name,
            'discount_type' => ($coupon->discount_type ?? CouponDiscountType::FIXED)->value,
            'discount_value' => (float) ($coupon->discount_value ?? 0),
            'target_type' => ($coupon->target_type?->value ?? 'all'),
            'provider' => strtolower(trim((string) $context->provider)),
            'duration_type' => ($coupon->duration_type ?? CouponDurationType::ONCE)->value,
            'duration_cycles' => $coupon->duration_cycles,
            'maximum_discounted_cycles' => $this->lifecycle->maximumDiscountedCycles($coupon),
            'cycle_index' => $context->cycleIndex,
            'billing_cycle' => $context->billingCycle,
            'original_amount_iqd' => $originalAmount,
            'discount_amount_iqd' => $discountAmount,
            'final_amount_iqd' => $finalAmount,
            'first_time_subscribers_only' => (bool) ($coupon->first_time_subscribers_only ?? false),
            'minimum_amount_iqd' => $coupon->minimum_amount_iqd !== null
                ? (int) round((float) $coupon->minimum_amount_iqd)
                : null,
        ];
    }

    public function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    public function appliesToCycle(Coupon $coupon, int $cycleIndex): bool
    {
        return $this->lifecycle->appliesToCycle($coupon, $cycleIndex);
    }

    public function recurringDurationCompatibilityMessage(
        CouponDurationType|string|null $durationType,
        string $provider = 'fib',
        ?int $durationCycles = null,
    ): ?string {
        $provider = strtolower(trim($provider));

        $durationType = $durationType instanceof CouponDurationType
            ? $durationType
            : (CouponDurationType::tryFrom((string) $durationType) ?? CouponDurationType::ONCE);

        if ($durationType === CouponDurationType::FOREVER) {
            return null;
        }

        if ($this->supportsDynamicRecurringDiscounts($provider)) {
            return null;
        }

        $providerLabel = strtoupper($provider ?: 'provider');

        return match ($durationType) {
            CouponDurationType::ONCE, CouponDurationType::FIRST_CYCLE => __('This coupon is configured to discount only the first subscription cycle, but the current :provider recurring integration creates one fixed recurring amount and cannot switch later renewals back to full price automatically. Use a forever recurring discount for plan or storage subscriptions instead.', [
                'provider' => $providerLabel,
            ]),
            CouponDurationType::FIRST_N_CYCLES => __('This coupon is configured to discount only the first :count subscription cycles, but the current :provider recurring integration creates one fixed recurring amount and cannot switch later renewals back to full price automatically. Use a forever recurring discount for plan or storage subscriptions instead.', [
                'count' => number_format(max(1, (int) ($durationCycles ?? 1))),
                'provider' => $providerLabel,
            ]),
            CouponDurationType::FOREVER => null,
        };
    }

    public function firstTimeSubscriber(Customer $customer): bool
    {
        $hasPaidPlanPayment = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('purchase_type', PurchaseType::PLAN_SUBSCRIPTION)
            ->where('status', 'paid')
            ->whereNotNull('fulfilled_at')
            ->exists();

        if ($hasPaidPlanPayment) {
            return false;
        }

        $hasPaidPlanSubscription = CustomerServiceSubscription::query()
            ->where('customer_id', $customer->id)
            ->whereHas('servicePlan', fn ($query) => $query->where('is_free', false))
            ->exists();

        return ! $hasPaidPlanSubscription;
    }

    public function activeCheckoutUseCountForCustomer(Coupon $coupon, Customer $customer): int
    {
        return CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('customer_id', $customer->id)
            ->where('redemption_type', CouponRedemptionType::CHECKOUT)
            ->whereIn('status', [
                CouponRedemptionStatus::RESERVED,
                CouponRedemptionStatus::APPLIED,
                CouponRedemptionStatus::CONSUMED,
            ])
            ->count();
    }

    public function assertEligible(Coupon $coupon, CouponContext $context, bool $checkUsageLimits = true): void
    {
        if (! (bool) ($coupon->is_active ?? false)) {
            $this->invalid(__('This coupon is currently inactive.'));
        }

        $startsAt = $this->resolveTimestamp($coupon->starts_at);
        $endsAt = $this->resolveTimestamp($coupon->ends_at);

        if ($startsAt?->isFuture()) {
            $this->invalid(__('This coupon is not active yet.'));
        }

        if ($endsAt?->isPast()) {
            $this->invalid(__('This coupon has expired.'));
        }

        if (! $this->supportsPaymentMethod($coupon, $context->provider)) {
            $this->invalid(__('This coupon is not available for the selected payment method.'));
        }

        if (! ($coupon->target_type?->supports($context->purchaseType) ?? false)) {
            $this->invalid(__('This coupon is not valid for this checkout.'));
        }

        $allowedCodes = collect($coupon->applies_to_codes ?? [])
            ->map(fn (mixed $value) => strtoupper(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        if ($allowedCodes !== [] && ! in_array(strtoupper($context->itemCode), $allowedCodes, true)) {
            $this->invalid(__('This coupon does not apply to the selected item.'));
        }

        $allowedCycles = collect($coupon->applies_to_billing_cycles ?? [])
            ->map(fn (mixed $value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        if ($allowedCycles !== [] && ! in_array(strtolower((string) $context->billingCycle), $allowedCycles, true)) {
            $this->invalid(__('This coupon does not apply to the selected billing cycle.'));
        }

        $minimumAmount = $coupon->minimum_amount_iqd !== null
            ? (int) round((float) $coupon->minimum_amount_iqd)
            : null;

        if ($minimumAmount !== null && $context->originalAmountIqd < $minimumAmount) {
            $this->invalid(__('This coupon requires a higher checkout amount.'));
        }

        if (! $this->lifecycle->appliesToCycle($coupon, $context->cycleIndex)) {
            $this->invalid(__('This coupon no longer applies to this billing cycle.'));
        }

        if ((bool) ($coupon->first_time_subscribers_only ?? false)) {
            if ($context->purchaseType !== PurchaseType::PLAN_SUBSCRIPTION) {
                $this->invalid(__('This coupon is only available for first-time plan subscribers.'));
            }

            if (! $this->firstTimeSubscriber($context->customer)) {
                $this->invalid(__('This coupon is only available to first-time plan subscribers.'));
            }
        }

        if ($context->isRecurring) {
            $message = $this->recurringDurationCompatibilityMessage(
                $coupon->duration_type ?? CouponDurationType::ONCE,
                (string) $context->provider,
                $coupon->duration_cycles,
            );

            if ($message !== null) {
                $this->invalid($message);
            }
        }

        if ($checkUsageLimits) {
            if ($coupon->max_total_uses !== null && (int) ($coupon->used_count ?? 0) >= (int) $coupon->max_total_uses) {
                $this->invalid(__('This coupon has reached its usage limit.'));
            }

            if ($coupon->max_uses_per_customer !== null
                && $this->activeCheckoutUseCountForCustomer($coupon, $context->customer) >= (int) $coupon->max_uses_per_customer) {
                $this->invalid(__('You have already used this coupon the maximum number of times.'));
            }
        }
    }

    protected function discountAmount(Coupon $coupon, int $originalAmount): int
    {
        $discountType = $coupon->discount_type ?? CouponDiscountType::FIXED;
        $value = (float) ($coupon->discount_value ?? 0);

        $discount = match ($discountType) {
            CouponDiscountType::PERCENT => (int) round($originalAmount * ($value / 100)),
            CouponDiscountType::FIXED => (int) round($value),
        };

        return max(0, min($originalAmount, $discount));
    }

    protected function findByCodeOrFail(?string $code): Coupon
    {
        $normalized = $this->normalizeCode($code);

        $coupon = Coupon::query()
            ->where('code', $normalized)
            ->first();

        if (! $coupon instanceof Coupon) {
            $this->invalid(__('This coupon code is invalid.'));
        }

        return $coupon;
    }

    protected function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'coupon' => $message,
        ]);
    }

    protected function resolveTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
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

    /**
     * @return array<int, string>
     */
    public function normalizedPaymentMethods(Coupon $coupon): array
    {
        $normalized = collect($coupon->supported_payment_methods ?? [])
            ->map(fn (mixed $value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        if ($normalized === []) {
            return ['fib'];
        }

        if (in_array('all', $normalized, true)) {
            return ['all'];
        }

        return array_values(array_unique($normalized));
    }

    public function supportsPaymentMethod(Coupon $coupon, string $provider): bool
    {
        $provider = strtolower(trim($provider));

        if ($provider === '') {
            return true;
        }

        $supported = $this->normalizedPaymentMethods($coupon);

        return in_array('all', $supported, true) || in_array($provider, $supported, true);
    }

    protected function supportsDynamicRecurringDiscounts(string $provider): bool
    {
        return match (strtolower(trim($provider))) {
            'fib' => false,
            'areeba' => (bool) config('payments.providers.areeba.supports.dynamic_recurring_amounts', false),
            default => false,
        };
    }
}

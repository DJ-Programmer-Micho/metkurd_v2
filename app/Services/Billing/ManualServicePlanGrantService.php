<?php

namespace App\Services\Billing;

use App\Enums\PaymentRecurringStrategy;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ManualServicePlanGrantService
{
    public function grant(Customer $customer, ServicePlan $plan, array $meta = []): CustomerServiceSubscription
    {
        return DB::transaction(function () use ($customer, $plan, $meta) {
            /** @var Customer $lockedCustomer */
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            /** @var CustomerServiceSubscription|null $currentSubscription */
            $currentSubscription = CustomerServiceSubscription::query()
                ->lockForUpdate()
                ->where('customer_id', $lockedCustomer->id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->latest('id')
                ->first();

            $billingCycle = strtolower(trim((string) ($meta['billing_cycle'] ?? 'monthly')));
            $startsAt = now();
            $periodEndsAt = $this->periodEnd($billingCycle, $startsAt);
            $previousPlanId = $currentSubscription?->service_plan_id;

            if ($currentSubscription instanceof CustomerServiceSubscription) {
                $currentMeta = array_merge((array) ($currentSubscription->meta ?? []), [
                    'superseded_at' => now()->toIso8601String(),
                    'superseded_by_service_plan_id' => (int) $plan->id,
                    'superseded_by_service_plan_code' => (string) $plan->code,
                    'superseded_by_admin_id' => $meta['admin_id'] ?? null,
                    'supersession_reason' => (string) ($meta['reason'] ?? ''),
                ]);

                $currentSubscription->forceFill([
                    'status' => 'ended',
                    'auto_renew' => false,
                    'ends_at' => $currentSubscription->ends_at ?? now(),
                    'canceled_at' => $currentSubscription->canceled_at ?? now(),
                    'meta' => $currentMeta,
                ])->save();
            }

            $subscriptionMeta = array_filter([
                'billing_source' => 'admin_manual_grant',
                'revenue_record' => false,
                'revenue_excluded' => true,
                'grant_type' => 'complimentary_internal',
                'grant_reason_code' => $meta['grant_reason_code'] ?? 'other',
                'provider' => null,
                'fib_subscription_id' => null,
                'reason' => (string) ($meta['reason'] ?? ''),
                'admin_id' => $meta['admin_id'] ?? null,
                'granted_at' => now()->toIso8601String(),
                'operation_id' => $meta['operation_id'] ?? null,
                'billing_cycle' => $billingCycle,
                'period_ends_at' => $periodEndsAt->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null);

            $subscription = CustomerServiceSubscription::query()->create([
                'customer_id' => (int) $lockedCustomer->id,
                'payment_id' => null,
                'coupon_id' => null,
                'service_plan_id' => (int) $plan->id,
                'previous_service_plan_id' => $previousPlanId,
                'status' => 'active',
                'source' => 'admin_manual_grant',
                'provider_ref' => null,
                'starts_at' => $startsAt,
                'ends_at' => $periodEndsAt,
                'canceled_at' => null,
                'upgraded_at' => $previousPlanId ? now() : null,
                'cycle_started_on' => $startsAt->toDateString(),
                'cycle_ends_on' => $periodEndsAt->toDateString(),
                'next_renewal_on' => $periodEndsAt->toDateString(),
                'auto_renew' => false,
                'customer_payment_method_id' => null,
                'renewal_strategy' => PaymentRecurringStrategy::MANUAL_RENEWAL->value,
                'price_iqd_snapshot' => null,
                'original_price_iqd_snapshot' => null,
                'discount_cycles_consumed' => 0,
                'display_currency_code' => null,
                'display_exchange_rate' => null,
                'display_amount_raw' => null,
                'display_amount_rounded' => null,
                'display_rounding_step' => null,
                'display_rounding_mode' => null,
                'display_country_code' => null,
                'meta' => $subscriptionMeta,
            ]);

            return $lockedCustomer->syncResolvedServicePlan($subscription)
                ->activeServiceSubscription()
                ->firstOrFail();
        }, 3);
    }

    protected function periodEnd(string $billingCycle, CarbonInterface $startsAt): CarbonInterface
    {
        return match ($billingCycle) {
            'yearly' => $startsAt->copy()->addYearNoOverflow(),
            default => $startsAt->copy()->addMonthNoOverflow(),
        };
    }
}

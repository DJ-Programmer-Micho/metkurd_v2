<?php

namespace App\Services\Payments;

use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Models\Payment;
use App\Enums\PaymentPurposeType;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\CustomerBillingStateService;
use Illuminate\Validation\ValidationException;

/** Checkout entry coordination only; provider creation and fulfillment stay in the existing actions. */
class CustomerPurchaseCheckout
{
    public function modelClass(string $kind): string
    {
        return match ($kind) {
            'service' => ServicePlan::class, 'storage' => StoragePlan::class, 'addon' => CreditProduct::class
        };
    }

    public function purpose(string $kind): PaymentPurposeType
    {
        return match ($kind) {
            'service' => PaymentPurposeType::SERVICE_PLAN, 'storage' => PaymentPurposeType::STORAGE_PLAN, 'addon' => PaymentPurposeType::CREDIT_PRODUCT
        };
    }

    public function pending(Customer $customer, string $kind): ?Payment
    {
        return app(\App\Domain\Payments\Support\PaymentCheckoutState::class)->blocker($customer, $this->modelClass($kind));
    }

    public function methods(string $kind, string $mode)
    {
        return app(PaymentMethodCatalog::class)->availableForPurpose($this->purpose($kind), 'IQD')
            // These are the provider capabilities of the existing Create* actions.
            ->filter(fn ($m) => $m->driver === 'fib' && ($mode !== 'recurring' || $m->supports_recurring))
            ->filter(fn ($m) => $kind !== 'addon' || $m->code === 'fib')->values();
    }

    public function storageReplacementBlocked(Customer $customer): bool
    {
        // Storage has no automatic retirement of the old provider subscription on replacement.
        // Local cancellation intent is not proof that the provider stopped collecting.
        return Payment::query()->where('customer_id', $customer->id)->where('purchasable_type', StoragePlan::class)
            ->where('provider_object_type', 'subscription')->whereNotNull('fib_subscription_id')->whereNotNull('fulfilled_at')
            ->where(fn ($q) => $q->whereNull('provider_subscription_status')->orWhereNotIn('provider_subscription_status', ['CANCELLED', 'CANCELED', 'EXPIRED']))->exists();
    }

    public function start(Customer $customer, string $kind, int $id, string $cycle, string $mode, string $method, ?string $coupon): Payment
    {
        $customer = $customer->fresh();
        if ($pending = $this->pending($customer, $kind)) {
            return $pending;
        }
        $model = $this->modelClass($kind);
        $item = $model::where('is_active', true)->findOrFail($id);
        $actualMode = $kind === 'addon' ? 'one_time' : $item->checkoutPaymentModeValue();
        if ($mode !== $actualMode || ($kind !== 'addon' && ! $item->supportsBillingInterval($cycle))) {
            throw ValidationException::withMessages(['checkout' => __('purchase_v2.selection_changed')]);
        }
        if (! $this->methods($kind, $actualMode)->contains('code', $method)) {
            throw ValidationException::withMessages(['checkout' => __('purchase_v2.unavailable')]);
        }
        if ($kind !== 'addon') {
            $state = $kind === 'service' ? app(CustomerBillingStateService::class)->servicePlanState($customer) : app(CustomerBillingStateService::class)->storageQuotaState($customer);
            if ((int) $state['current_plan_id'] === $id) {
                throw ValidationException::withMessages(['checkout' => __('purchase_v2.same_plan')]);
            }
            if (($kind === 'service' && $item->is_free) || ($kind === 'storage' && $item->priceIqdAmount() <= 0)) {
                throw ValidationException::withMessages(['checkout' => __('purchase_v2.free_change')]);
            }
        }
        if ($kind === 'storage' && $mode === 'one_time' && $cycle !== 'monthly') {
            throw ValidationException::withMessages(['checkout' => __('purchase_v2.storage_interval_blocked')]);
        }
        if ($kind === 'storage' && $this->storageReplacementBlocked($customer)) {
            throw ValidationException::withMessages(['checkout' => __('purchase_v2.storage_replace_blocked')]);
        }

        return match ($kind) {
            'service' => app(CreatePlanSubscriptionPayment::class)->handle($customer, $id, $cycle, $coupon, $method, true),
            'storage' => app(CreateStorageSubscriptionPayment::class)->handle($customer, $id, $cycle, $coupon, $method, true),
            'addon' => app(CreateAddonPayment::class)->handle($customer, $id, $coupon, true),
        };
    }
}

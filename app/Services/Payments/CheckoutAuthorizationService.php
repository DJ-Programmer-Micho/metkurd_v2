<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurposeType;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ServicePlan;
use App\Services\Billing\CustomerBillingStateService;
use Illuminate\Auth\Access\AuthorizationException;

class CheckoutAuthorizationService
{
    public function __construct(
        protected PaymentMethodCatalog $paymentMethods,
        protected PaymentProviderManager $providers,
        protected CustomerBillingStateService $billingState,
    ) {
    }

    public function assertProviderEnabled(string $provider): void
    {
        $provider = strtolower(trim($provider));
        $enabled = $this->providers->isEnabled($provider);

        if (! $enabled) {
            throw new AuthorizationException(__('The selected payment provider is not enabled.'));
        }
    }

    public function assertPaymentMethodAvailable(
        string $paymentMethodCode,
        PaymentPurposeType|string $purposeType,
        string $currencyCode = 'IQD',
    ): PaymentMethod {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        $method = $this->paymentMethods->resolveCheckoutMethod(
            $purposeType,
            $currencyCode,
            $paymentMethodCode,
        );

        if (! $method instanceof PaymentMethod) {
            throw new AuthorizationException(__('The selected payment method is not available for this checkout.'));
        }

        return $method;
    }

    /**
     * @return array<int, array{code:string,name:string,description:?string,icon:?string,driver:string}>
     */
    public function paymentMethodOptions(PaymentPurposeType|string $purposeType, string $currencyCode = 'IQD'): array
    {
        return $this->paymentMethods->checkoutOptions($purposeType, $currencyCode);
    }

    public function assertCanPurchase(Customer $customer, PaymentPurposeType|string $purposeType): void
    {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        if ($purposeType === PaymentPurposeType::CREDIT_PRODUCT) {
            $this->assertAddonPurchaseAllowed($customer);
        }
    }

    public function assertAddonPurchaseAllowed(Customer $customer): void
    {
        $plan = $this->resolveCurrentPlan($customer);

        if (! $plan instanceof ServicePlan || (bool) $plan->is_free) {
            throw new AuthorizationException(__('Add-on credits are only available for customers with an active paid plan.'));
        }
    }

    /**
     * @return array{allowed:bool,reason:?string,plan_code:string,plan_name:string}
     */
    public function addonPurchaseState(Customer $customer): array
    {
        $plan = $this->resolveCurrentPlan($customer);
        $planCode = (string) ($plan?->code ?? 'free');
        $planName = (string) ($plan?->name ?? __('Free'));

        if (! $plan instanceof ServicePlan || (bool) $plan->is_free) {
            return [
                'allowed' => false,
                'reason' => __('Add-on credits are only available for customers with an active paid plan.'),
                'plan_code' => $planCode,
                'plan_name' => $planName,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'plan_code' => $planCode,
            'plan_name' => $planName,
        ];
    }

    protected function resolveCurrentPlan(Customer $customer): ?ServicePlan
    {
        return $this->billingState->servicePlanState($customer)['current_plan'] ?? null;
    }

    public function recurringStrategyForMethod(PaymentMethod $method, PaymentPurposeType|string $purposeType): string
    {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        if ($purposeType === PaymentPurposeType::CREDIT_PRODUCT) {
            return 'none';
        }

        return $this->providers->driver($method->driver)->recurringStrategy($method, $purposeType);
    }

    public function recurringStrategy(string $provider, PaymentPurposeType|string $purposeType): string
    {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        $method = $this->paymentMethods->firstByDriver($provider);

        if ($method instanceof PaymentMethod) {
            return $this->recurringStrategyForMethod($method, $purposeType);
        }

        if (strtolower(trim($provider)) === 'fib' && $purposeType !== PaymentPurposeType::CREDIT_PRODUCT) {
            return 'provider_schedule';
        }

        return $purposeType === PaymentPurposeType::CREDIT_PRODUCT ? 'none' : 'manual_renewal';
    }
}

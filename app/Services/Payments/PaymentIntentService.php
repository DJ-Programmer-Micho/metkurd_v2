<?php

namespace App\Services\Payments;

use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentPurposeType;
use App\Jobs\Payments\SyncPendingPaymentIntentJob;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentIntentService
{
    public function __construct(
        protected CheckoutAuthorizationService $authorization,
        protected PaymentMethodCatalog $paymentMethods,
        protected PaymentProviderManager $providers,
        protected PaymentFeeCalculator $feeCalculator,
        protected PaymentFulfillmentService $fulfillmentService,
        protected PaymentIntentReconciliationService $reconciler,
    ) {
    }

    public function startCheckout(Customer $customer, PaymentPurposeType|string $purposeType, int $purposeId, array $options = []): PaymentIntent
    {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType
            : PaymentPurposeType::from((string) $purposeType);

        $this->authorization->assertCanPurchase($customer, $purposeType);

        $purpose = $this->resolvePurpose($purposeType, $purposeId, $options);
        $paymentMethod = $this->resolvePaymentMethod($purposeType, $options);
        $provider = $this->providers->driver($paymentMethod->driver);
        $fees = $this->feeCalculator->quote($paymentMethod, $purpose['base_amount_iqd'], $options['card_origin'] ?? null);
        $snapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd(
            $fees['gross_amount_iqd'],
            $customer,
            $options
        );

        return DB::transaction(function () use ($customer, $purposeType, $purposeId, $options, $purpose, $paymentMethod, $provider, $fees, $snapshot) {
            $merchantTransactionId = $this->merchantTransactionId($paymentMethod->driver);

            $intent = PaymentIntent::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'provider' => $paymentMethod->driver,
                'payment_method' => $paymentMethod->code,
                'purpose_type' => $purposeType->value,
                'purpose_id' => $purposeId,
                'purpose_code' => $purpose['code'],
                'purpose_name' => $purpose['name'],
                'billing_interval' => $purpose['billing_interval'],
                'is_recurring' => $purpose['is_recurring'],
                'recurring_strategy' => $this->authorization->recurringStrategyForMethod($paymentMethod, $purposeType),
                'base_currency_code' => 'IQD',
                'base_amount_iqd' => $fees['base_amount_iqd'],
                'gross_amount_iqd' => $fees['gross_amount_iqd'],
                'surcharge_amount_iqd' => $fees['surcharge_amount_iqd'],
                'provider_fee_amount_iqd' => $fees['provider_fee_amount_iqd'],
                'net_amount_iqd' => $fees['net_amount_iqd'],
                'fee_currency_code' => 'IQD',
                'display_currency_code' => $snapshot['display_currency_code'],
                'display_exchange_rate' => $snapshot['display_exchange_rate'],
                'display_amount_raw' => $snapshot['display_amount_raw'],
                'display_amount_rounded' => $snapshot['display_amount_rounded'],
                'display_rounding_step' => $snapshot['display_rounding_step'],
                'display_rounding_mode' => $snapshot['display_rounding_mode'],
                'display_country_code' => $snapshot['display_country_code'],
                'status' => PaymentIntentStatus::PENDING->value,
                'idempotency_key' => (string) Str::uuid(),
                'merchant_transaction_id' => $merchantTransactionId,
                'meta' => [
                    'payment_method_name' => $paymentMethod->name,
                    'payment_method_icon' => $paymentMethod->icon,
                    'fee_breakdown' => $fees['fee_breakdown'],
                    'display_label' => $snapshot['display_label'],
                    'base_label' => $snapshot['base_label'],
                    'iqd_label' => $snapshot['iqd_label'],
                    'usd_reference_label' => $snapshot['usd_reference_label'],
                    'currency_resolution_source' => $snapshot['currency_resolution_source'],
                    'ui' => $options['ui'] ?? null,
                ],
            ]);

            $checkout = $provider->initializeCheckout($intent, $paymentMethod, $customer, $purpose, $options);

            $intent = $this->reconciler->applyCheckoutResponse($intent, $fees, $checkout);

            if ((string) $intent->status === PaymentIntentStatus::PAID->value) {
                $this->fulfillmentService->fulfill($intent);
            }

            if ($intent->requiresCustomerAction()) {
                SyncPendingPaymentIntentJob::dispatch((int) $intent->id)
                    ->delay(now()->addSeconds((int) config('fib.status_sync.delay_seconds', 15)));
            }

            return $intent->fresh(['transactions', 'creditOrders']);
        }, 3);
    }

    public function syncCheckout(PaymentIntent|int $intent, array $options = []): PaymentIntent
    {
        $intent = $intent instanceof PaymentIntent
            ? $intent->fresh(['customer'])
            : PaymentIntent::query()->with(['customer'])->findOrFail($intent);

        if (! $intent instanceof PaymentIntent) {
            throw new \RuntimeException(__('Payment intent not found.'));
        }

        if ($intent->isTerminal()) {
            return $intent->fresh(['transactions', 'creditOrders']);
        }

        $paymentMethod = $this->paymentMethods->find((string) $intent->payment_method)
            ?? $this->paymentMethods->firstByDriver((string) $intent->provider);

        if (! $paymentMethod instanceof PaymentMethod) {
            throw new \RuntimeException(__('The payment method for this intent is no longer available.'));
        }

        $provider = $this->providers->driver($paymentMethod->driver);
        $checkout = $provider->synchronizeCheckout($intent, $paymentMethod, $options);

        return DB::transaction(function () use ($intent, $checkout) {
            $freshIntent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $updatedIntent = $this->reconciler->applyCheckoutResponse($freshIntent, $this->feesFromIntent($freshIntent), $checkout);

            if ((string) $updatedIntent->status === PaymentIntentStatus::PAID->value) {
                $this->fulfillmentService->fulfill($updatedIntent);
            }

            return $updatedIntent->fresh(['transactions', 'creditOrders']);
        }, 3);
    }

    /**
     * @return array{code:string,name:string,base_amount_iqd:int,billing_interval:?string,is_recurring:bool}
     */
    protected function resolvePurpose(PaymentPurposeType $purposeType, int $purposeId, array $options): array
    {
        return match ($purposeType) {
            PaymentPurposeType::SERVICE_PLAN => $this->resolveServicePlanPurpose($purposeId, $options),
            PaymentPurposeType::STORAGE_PLAN => $this->resolveStoragePlanPurpose($purposeId),
            PaymentPurposeType::CREDIT_PRODUCT => $this->resolveCreditProductPurpose($purposeId),
        };
    }

    protected function resolveServicePlanPurpose(int $planId, array $options): array
    {
        $plan = ServicePlan::query()->where('is_active', true)->findOrFail($planId);
        $billingCycle = strtolower(trim((string) ($options['billing_cycle'] ?? 'monthly')));
        $billingCycle = in_array($billingCycle, ['monthly', 'yearly'], true) ? $billingCycle : 'monthly';

        return [
            'code' => (string) $plan->code,
            'name' => (string) $plan->name,
            'base_amount_iqd' => $plan->priceIqdForCycle($billingCycle),
            'billing_interval' => $billingCycle,
            'is_recurring' => true,
        ];
    }

    protected function resolveStoragePlanPurpose(int $planId): array
    {
        $plan = StoragePlan::query()->where('is_active', true)->findOrFail($planId);

        return [
            'code' => (string) $plan->code,
            'name' => (string) $plan->name,
            'base_amount_iqd' => $plan->priceIqdAmount(),
            'billing_interval' => 'monthly',
            'is_recurring' => true,
        ];
    }

    protected function resolveCreditProductPurpose(int $productId): array
    {
        $product = CreditProduct::query()->where('is_active', true)->findOrFail($productId);

        return [
            'code' => (string) $product->code,
            'name' => (string) $product->name,
            'base_amount_iqd' => $product->priceIqdAmount(),
            'billing_interval' => null,
            'is_recurring' => false,
        ];
    }

    protected function resolvePaymentMethod(PaymentPurposeType $purposeType, array $options): PaymentMethod
    {
        $requestedMethod = trim((string) ($options['payment_method'] ?? ''));
        $requestedProvider = trim((string) ($options['provider'] ?? ''));

        $method = $this->paymentMethods->resolveCheckoutMethod(
            $purposeType,
            'IQD',
            $requestedMethod !== '' ? $requestedMethod : null,
            $requestedProvider !== '' ? $requestedProvider : null,
        );

        if (! $method instanceof PaymentMethod) {
            throw new \RuntimeException(__('No checkout-ready payment method is currently available for this purchase.'));
        }

        return $method;
    }

    protected function merchantTransactionId(string $driver): string
    {
        return strtoupper($driver) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8));
    }

    /**
     * @return array<string, mixed>
     */
    protected function feesFromIntent(PaymentIntent $intent): array
    {
        return [
            'base_amount_iqd' => (int) round((float) $intent->base_amount_iqd),
            'gross_amount_iqd' => (int) round((float) $intent->gross_amount_iqd),
            'surcharge_amount_iqd' => (int) round((float) $intent->surcharge_amount_iqd),
            'provider_fee_amount_iqd' => (int) round((float) $intent->provider_fee_amount_iqd),
            'net_amount_iqd' => (int) round((float) $intent->net_amount_iqd),
            'fee_breakdown' => (array) data_get($intent->meta ?? [], 'fee_breakdown', []),
        ];
    }
}

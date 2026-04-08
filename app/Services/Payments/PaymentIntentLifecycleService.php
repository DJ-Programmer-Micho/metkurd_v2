<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

class PaymentIntentLifecycleService
{
    public function __construct(
        protected PaymentMethodCatalog $paymentMethods,
        protected PaymentProviderManager $providers,
        protected PaymentIntentReconciliationService $reconciler,
    ) {
    }

    public function cancel(PaymentIntent|int $intent, array $options = []): PaymentIntent
    {
        return $this->applyLifecycleAction($intent, 'cancelCheckout', $options);
    }

    public function refund(PaymentIntent|int $intent, array $options = []): PaymentIntent
    {
        return $this->applyLifecycleAction($intent, 'refundCheckout', $options);
    }

    protected function applyLifecycleAction(PaymentIntent|int $intent, string $methodName, array $options = []): PaymentIntent
    {
        $intent = $intent instanceof PaymentIntent
            ? $intent->fresh(['customer'])
            : PaymentIntent::query()->with(['customer'])->findOrFail($intent);

        $paymentMethod = $this->resolvePaymentMethod($intent);
        $provider = $this->providers->driver((string) $paymentMethod->driver);
        $checkout = $provider->{$methodName}($intent, $paymentMethod, $options);

        return DB::transaction(function () use ($intent, $checkout) {
            $freshIntent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

            return $this->reconciler->applyCheckoutResponse(
                $freshIntent,
                $this->feesFromIntent($freshIntent),
                $checkout,
            )->fresh(['transactions', 'creditOrders']);
        }, 3);
    }

    protected function resolvePaymentMethod(PaymentIntent $intent): PaymentMethod
    {
        $paymentMethod = $this->paymentMethods->find((string) $intent->payment_method)
            ?? $this->paymentMethods->firstByDriver((string) $intent->provider);

        if (! $paymentMethod instanceof PaymentMethod) {
            throw new \RuntimeException(__('The payment method for this intent is no longer available.'));
        }

        return $paymentMethod;
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

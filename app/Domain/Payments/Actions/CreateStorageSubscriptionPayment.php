<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Customer;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Payments\PaymentFeeCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateStorageSubscriptionPayment
{
    public function __construct(
        protected \App\Domain\Payments\Fib\FibPaymentService $fib,
        protected BillingCurrencyService $currency,
        protected PaymentFeeCalculator $fees,
        protected PaymentEventRecorder $events,
    ) {
    }

    public function handle(Customer $customer, int $planId): Payment
    {
        $plan = StoragePlan::query()->where('is_active', true)->findOrFail($planId);
        $currentPlan = $customer->currentStoragePlan();

        if ((int) ($currentPlan?->id ?? 0) === (int) $plan->id) {
            throw ValidationException::withMessages([
                'plan' => __('This is already your current storage plan.'),
            ]);
        }

        $baseAmountIqd = $plan->priceIqdAmount();
        $feeQuote = $this->fees->quote('fib', $baseAmountIqd);
        $grossAmountIqd = (int) ($feeQuote['gross_amount_iqd'] ?? $baseAmountIqd);
        $display = $this->currency->priceDataForBaseAmountIqd($grossAmountIqd, $customer);
        $baseDisplay = $this->currency->priceDataForBaseAmountIqd($baseAmountIqd, $customer);
        $payment = DB::transaction(function () use ($customer, $plan, $baseAmountIqd, $grossAmountIqd, $feeQuote, $display, $baseDisplay) {
            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'provider' => PaymentProvider::FIB,
                'purchase_type' => PurchaseType::STORAGE_SUBSCRIPTION,
                'payment_mode' => PaymentMode::RECURRING,
                'status' => PaymentStatus::PENDING,
                'local_reference' => $this->localReference('STORAGE'),
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $grossAmountIqd,
                'currency' => 'IQD',
                'purchase_snapshot' => [
                    'code' => (string) $plan->code,
                    'name' => (string) $plan->name,
                    'quota_mb' => (int) ($plan->quota_mb ?? 0),
                    'amount_iqd' => $baseAmountIqd,
                    'gross_amount_iqd' => $grossAmountIqd,
                    'display' => $display,
                    'base_display' => $baseDisplay,
                    'fee_quote' => $feeQuote,
                    'renewal_strategy' => 'manual_renewal',
                ],
                'meta' => [
                    'locale' => app()->getLocale(),
                    'fee_quote' => $feeQuote,
                    'manual_renewal_notice' => 'The published FIB docs do not describe provider-managed recurring billing, so recurring purchases remain app-level subscriptions with manual renewal.',
                ],
                'purchasable_type' => StoragePlan::class,
                'purchasable_id' => $plan->id,
            ]);

            $this->events->record($payment, [
                'event_type' => 'local_payment_created',
                'source' => 'customer_checkout',
                'after_status' => $payment->status->value,
                'payload' => $payment->snapshot(),
            ]);

            return $payment;
        });

        try {
            $result = $this->fib->createPayment(
                $payment,
                route('payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $payment])
            );

            /** @var \App\Domain\Payments\Data\FibCreatePaymentRequestData $request */
            $request = $result['request'];
            /** @var \App\Domain\Payments\Data\FibCreatePaymentResponseData $response */
            $response = $result['response'];

            $payment->forceFill([
                'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
                'provider_status' => 'UNPAID',
                'fib_payment_id' => $response->paymentId,
                'readable_code' => $response->readableCode,
                'qr_code' => $response->qrCode,
                'provider_links' => $response->providerLinks,
                'valid_until' => $response->validUntil,
                'create_payload' => $request->toArray(),
                'create_response' => $response->raw,
            ])->save();

            $this->events->record($payment, [
                'event_type' => 'provider_payment_created',
                'source' => 'customer_checkout',
                'before_status' => PaymentStatus::PENDING->value,
                'after_status' => PaymentStatus::AWAITING_CUSTOMER_ACTION->value,
                'payload' => $response->raw,
                'meta' => [
                    'create_payload' => $request->toArray(),
                ],
            ]);

            return $payment->fresh();
        } catch (\Throwable $exception) {
            $payment->forceFill([
                'status' => PaymentStatus::FAILED,
                'status_reason' => $exception->getMessage(),
            ])->save();

            $this->events->record($payment, [
                'event_type' => 'provider_payment_create_failed',
                'source' => 'customer_checkout',
                'before_status' => PaymentStatus::PENDING->value,
                'after_status' => PaymentStatus::FAILED->value,
                'meta' => [
                    'message' => $exception->getMessage(),
                ],
            ]);

            throw $exception;
        }
    }

    protected function localReference(string $prefix): string
    {
        return sprintf('FIB-%s-%s-%s', $prefix, now()->format('YmdHis'), strtoupper(Str::random(8)));
    }
}

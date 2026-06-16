<?php

namespace App\Listeners\Payments;

use App\Domain\Payments\Actions\FulfillAddonCredits;
use App\Domain\Payments\Actions\FulfillPlanSubscription;
use App\Domain\Payments\Actions\FulfillStorageSubscription;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Events\Payments\PaymentConfirmed;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Support\Facades\Log;

class RunPaymentFulfillment
{
    public function __construct(
        protected FulfillPlanSubscription $plans,
        protected FulfillStorageSubscription $storage,
        protected FulfillAddonCredits $addons,
        protected TelegramSubscriptionLifecycleNotifier $telegramLifecycleNotifier,
    ) {}

    public function handle(PaymentConfirmed $event): void
    {
        $payment = Payment::query()->with(['customer.profile'])->findOrFail($event->paymentId);

        if ($payment->isApplied()) {
            Log::info('fib.apply.already_applied', $this->logContext($payment));

            return;
        }

        Log::info('fib.apply.started', $this->logContext($payment));

        try {
            // Checkout mode affects provider flow only; fulfillment remains purchase-type based.
            foreach ([$this->plans, $this->storage, $this->addons] as $handler) {
                if ($handler->supports($payment->purchase_type)) {
                    $handler->handle($payment);

                    $payment = $payment->fresh();

                    Log::info(
                        $payment?->isApplied() ? 'fib.apply.success' : 'fib.apply.noop',
                        $this->logContext($payment)
                    );

                    return;
                }
            }
        } catch (\Throwable $exception) {
            Log::error('fib.apply.failed', array_merge(
                $this->logContext($payment),
                ['message' => $exception->getMessage()]
            ));

            $this->telegramLifecycleNotifier->send(
                'FIB payment application failed',
                [
                    'Customer ID' => $payment->customer_id,
                    'Username' => (string) ($payment->customer?->username ?? ''),
                    'Purchase Type' => (string) ($payment->purchase_type?->value ?? ''),
                    'Payment Status' => (string) ($payment->status?->value ?? ''),
                    'Application Status' => $payment->applicationStatusLabel(),
                    'Provider' => strtoupper((string) ($payment->provider?->value ?? '')),
                    'Provider Ref' => $payment->providerReference(),
                    'Local Ref' => (string) $payment->local_reference,
                    'Amount' => (string) round((float) $payment->amount).' '.(string) $payment->currency,
                    'Reason' => $exception->getMessage(),
                ],
                'FIB payment application failure'
            );

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function logContext(Payment $payment): array
    {
        return [
            'payment_id' => (int) $payment->id,
            'local_reference' => (string) $payment->local_reference,
            'customer_id' => (int) $payment->customer_id,
            'purchase_type' => (string) ($payment->purchase_type?->value ?? ''),
            'provider_reference' => $payment->providerReference(),
            'provider_status' => $payment->providerStatusLabel(),
            'payment_status' => (string) ($payment->status?->value ?? PaymentStatus::PENDING->value),
            'application_status' => $payment->applicationStatusLabel(),
            'amount' => (float) $payment->amount,
            'purchasable_type' => $payment->purchasable_type,
            'purchasable_id' => $payment->purchasable_id,
            'fulfilled_at' => optional($payment->fulfilled_at)->toIso8601String(),
        ];
    }
}
